<?php

declare(strict_types=1);

namespace Kanopi\Composer\Assets;

use Composer\Composer;
use Composer\Factory;
use Composer\IO\IOInterface;
use Composer\Package\PackageInterface;
use Composer\Package\RootPackageInterface;
use Composer\Util\ProcessExecutor;
use Kanopi\Composer\Assets\Drift\Drift;
use Kanopi\Composer\Assets\Drift\DriftChecker;
use Kanopi\Composer\Assets\Operations\OperationFactory;

/**
 * Orchestrates a single asset-scaffolding run.
 *
 * Merges the `file-mapping` of every allowed package (root last, so it wins),
 * runs each resulting operation, then maintains `.gitignore` for generated
 * files and fires the `post-composer-assets-cmd` script event.
 */
final class Handler
{
    public const POST_CMD = 'post-composer-assets-cmd';

    public function __construct(
        private readonly Composer $composer,
        private readonly IOInterface $io,
    ) {
    }

    public function run(bool $devMode = true, bool $dryRun = false): void
    {
        $rootOptions = $this->optionsFor($this->composer->getPackage());
        $mappings = $this->buildMappings();

        if ($mappings === []) {
            $this->io->write('<info>composer-assets: no file-mapping configured; nothing to do.</info>', true, IOInterface::VERBOSE);

            return;
        }

        if ($dryRun) {
            $this->io->write('<comment>composer-assets: dry run — no files will be written.</comment>');
        }
        $this->io->write('<info>Scaffolding asset files</info>');

        /** @var list<AssetFilePath> $managed */
        $managed = [];
        foreach ($mappings as $info) {
            if ($info->process($this->io, $rootOptions->symlink(), $dryRun)) {
                $managed[] = $info->destination();
            }
        }

        // A dry run reports planned operations only: skip the .gitignore writes
        // and the post-run script event, both of which mutate state.
        if (!$dryRun) {
            $this->manageGitignore($rootOptions->gitignore(), $this->projectRoot(), $managed);
        }

        // Surface owned files that have drifted from their package source. This
        // is warn-only here; "composer assets:check" reports the diffs and can
        // fail when "fail-on-drift" is configured.
        $this->warnOnDrift((new DriftChecker($this->io))->check($mappings, $rootOptions->symlink()));

        if (!$dryRun) {
            $this->composer->getEventDispatcher()->dispatchScript(self::POST_CMD, $devMode);
        }
    }

    /**
     * Detects drift for owned files without writing anything.
     *
     * @param list<string> $only limit to these destination paths; empty = all
     * @return list<Drift>
     */
    public function checkDrift(array $only = []): array
    {
        $rootOptions = $this->optionsFor($this->composer->getPackage());

        return (new DriftChecker($this->io))->check($this->buildMappings(), $rootOptions->symlink(), $only);
    }

    /**
     * Whether the root project configured drift to be a hard failure.
     */
    public function failOnDrift(): bool
    {
        return $this->optionsFor($this->composer->getPackage())->failOnDrift();
    }

    /**
     * Reports every managed file with its provider, operation, and current state
     * ("in sync" / "drifted" / "missing" / "skipped"). Read-only.
     *
     * @param list<string> $only limit to these destination paths; empty = all
     * @return list<array{path: string, provider: string, operation: string, state: string}>
     */
    public function status(array $only = []): array
    {
        $rootOptions = $this->optionsFor($this->composer->getPackage());
        $mappings = $this->buildMappings();

        if ($only !== []) {
            foreach ($only as $path) {
                if (!array_key_exists($path, $mappings)) {
                    $this->io->writeError(sprintf(
                        '<warning>composer-assets: "%s" is not a managed file; skipping.</warning>',
                        $path,
                    ));
                }
            }
        }

        $drifted = [];
        foreach ((new DriftChecker($this->io))->check($mappings, $rootOptions->symlink()) as $drift) {
            $drifted[$drift->label()] = true;
        }

        $rows = [];
        foreach ($mappings as $path => $info) {
            $path = (string) $path;
            if ($only !== [] && !in_array($path, $only, true)) {
                continue;
            }
            $kind = $info->operation()->kind();
            $rows[] = [
                'path' => $path,
                'provider' => $info->provider() !== '' ? $info->provider() : '(root)',
                'operation' => $kind,
                'state' => $this->stateFor($kind, $info->destination()->exists(), isset($drifted[$path])),
            ];
        }

        return $rows;
    }

    private function stateFor(string $kind, bool $exists, bool $drifted): string
    {
        if ($kind === 'skip') {
            return 'skipped';
        }
        if (!$exists) {
            return 'missing';
        }

        return $drifted ? 'drifted' : 'in sync';
    }

    /**
     * Merges the file-mappings of every allowed package in precedence order
     * (later packages override earlier ones for the same destination).
     *
     * @return array<string, AssetFileInfo>
     */
    private function buildMappings(): array
    {
        $projectRoot = $this->projectRoot();
        $globalMode = $this->optionsFor($this->composer->getPackage())->mode();
        $evaluator = $this->conditionEvaluator($projectRoot);
        $allowed = (new AllowedPackages($this->composer, $this->io))->getAllowedPackages();

        $mappings = [];
        foreach ($allowed as $package) {
            $options = $this->optionsFor($package);
            if (!$options->hasFileMapping() && !$options->hasConditional()) {
                continue;
            }
            $packageRoot = $this->installPath($package, $projectRoot);
            $factory = new OperationFactory($package->getName(), $packageRoot, $globalMode);
            $raw = $evaluator->mergeConditionalGroups($options->fileMapping(), $options->conditional());
            $resolved = $evaluator->resolve($raw);
            $expanded = (new MappingExpander($this->io))->expand($resolved, $packageRoot);
            foreach ($expanded as $destination => $value) {
                $dest = AssetFilePath::destination($projectRoot, (string) $destination);
                $driftCheck = true;
                if (is_array($value) && array_key_exists('drift', $value)) {
                    $driftCheck = (bool) $value['drift'];
                }
                $mappings[(string) $destination] = new AssetFileInfo(
                    $dest,
                    $factory->create((string) $destination, $value),
                    $driftCheck,
                    $package->getName(),
                );
            }
        }

        return $mappings;
    }

    /**
     * @param list<Drift> $drifts
     */
    private function warnOnDrift(array $drifts): void
    {
        foreach ($drifts as $drift) {
            $this->io->writeError(sprintf(
                '<warning>composer-assets: %s has drifted from its package source '
                . '(run "composer assets:check" for the diff).</warning>',
                $drift->label(),
            ));
        }
    }

    /**
     * @param list<AssetFilePath> $managed
     */
    private function manageGitignore(?bool $option, string $projectRoot, array $managed): void
    {
        $git = new Git(new ProcessExecutor($this->io));
        $manager = new ManageGitIgnore($projectRoot, $git, $this->io);
        $vendorDir = (string) $this->composer->getConfig()->get('vendor-dir');
        if ($manager->isEnabled($option, $vendorDir)) {
            $manager->manage($managed);
        }
    }

    /**
     * Builds the condition evaluator from the current install: installed package
     * versions, the effective PHP version (config.platform.php, else runtime),
     * the environment, and the project root.
     */
    private function conditionEvaluator(string $projectRoot): ConditionEvaluator
    {
        $versions = [];
        foreach ($this->composer->getRepositoryManager()->getLocalRepository()->getPackages() as $package) {
            $versions[$package->getName()] = $package->getPrettyVersion();
        }

        $platform = $this->composer->getConfig()->get('platform');
        $php = is_array($platform) && isset($platform['php']) && $platform['php'] !== ''
            ? (string) $platform['php']
            : PHP_VERSION;

        $env = getenv();

        return new ConditionEvaluator($versions, $php, is_array($env) ? $env : [], $projectRoot);
    }

    private function optionsFor(PackageInterface $package): AssetsOptions
    {
        $extra = $package->getExtra()['composer-assets'] ?? [];

        return AssetsOptions::create(is_array($extra) ? $extra : []);
    }

    private function installPath(PackageInterface $package, string $projectRoot): string
    {
        if ($package instanceof RootPackageInterface) {
            return $projectRoot;
        }

        $path = $this->composer->getInstallationManager()->getInstallPath($package);

        return $path !== null && $path !== '' ? $path : $projectRoot;
    }

    private function projectRoot(): string
    {
        $root = realpath(dirname(Factory::getComposerFile()));

        return $root !== false ? $root : (string) getcwd();
    }
}
