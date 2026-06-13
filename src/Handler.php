<?php

declare(strict_types=1);

namespace Kanopi\Composer\Assets;

use Composer\Composer;
use Composer\Factory;
use Composer\IO\IOInterface;
use Composer\Package\PackageInterface;
use Composer\Package\RootPackageInterface;
use Composer\Util\ProcessExecutor;
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

    public function run(bool $devMode = true): void
    {
        $root = $this->composer->getPackage();
        $rootOptions = $this->optionsFor($root);
        $projectRoot = $this->projectRoot();

        $allowed = (new AllowedPackages($this->composer, $this->io))->getAllowedPackages();

        // Merge file-mappings in precedence order (later packages override).
        /** @var array<string, AssetFileInfo> $mappings */
        $mappings = [];
        foreach ($allowed as $package) {
            $options = $this->optionsFor($package);
            if (!$options->hasFileMapping()) {
                continue;
            }
            $factory = new OperationFactory($package->getName(), $this->installPath($package, $projectRoot));
            foreach ($options->fileMapping() as $destination => $value) {
                $dest = AssetFilePath::destination($projectRoot, (string) $destination);
                $mappings[(string) $destination] = new AssetFileInfo($dest, $factory->create((string) $destination, $value));
            }
        }

        if ($mappings === []) {
            $this->io->write('<info>composer-assets: no file-mapping configured; nothing to do.</info>', true, IOInterface::VERBOSE);

            return;
        }

        $this->io->write('<info>Scaffolding asset files</info>');

        /** @var list<AssetFilePath> $managed */
        $managed = [];
        foreach ($mappings as $info) {
            if ($info->process($this->io, $rootOptions->symlink())) {
                $managed[] = $info->destination();
            }
        }

        $this->manageGitignore($rootOptions->gitignore(), $projectRoot, $managed);

        $this->composer->getEventDispatcher()->dispatchScript(self::POST_CMD, $devMode);
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
