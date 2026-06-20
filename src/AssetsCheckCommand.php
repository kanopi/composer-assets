<?php

declare(strict_types=1);

namespace Kanopi\Composer\Assets;

use Composer\Command\BaseCommand;
use Kanopi\Composer\Assets\Drift\UnifiedDiff;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * `composer assets:check` — reports drift between owned project files and the
 * content their providing packages would produce, without writing anything.
 *
 * Read-only: useful in CI to catch a project-owned file (an `overwrite: false`
 * copy, or a `force-append` / `force-merge` target) that has fallen behind the
 * package it came from. Warn-only by default; fails (exit 1) when `--strict` is
 * passed or `extra.composer-assets.fail-on-drift` is set.
 */
final class AssetsCheckCommand extends BaseCommand
{
    use NormalizesPaths;

    protected function configure(): void
    {
        $this
            ->setName('assets:check')
            ->setDescription('Report drift between owned project files and their package sources.')
            ->addArgument(
                'paths',
                InputArgument::IS_ARRAY | InputArgument::OPTIONAL,
                'Limit the check to these destination paths (project-relative, matching the file-mapping keys). Default: all owned files.',
            )
            ->addOption('strict', null, InputOption::VALUE_NONE, 'Exit non-zero if any file has drifted (overrides config).')
            ->addOption('format', null, InputOption::VALUE_REQUIRED, 'Output format: "text" (default) or "json".', 'text')
            ->setHelp(
                'Compares each owned destination file ("overwrite": false copies and '
                . '"force-append"/"force-merge" targets) against what its package would '
                . 'produce, printing a unified diff for each divergence. Does not modify '
                . 'any files. Pass one or more paths to limit the report to those files, '
                . 'e.g. "composer assets:check web/robots.txt". Use --format=json for '
                . 'machine-readable output (CI/dashboards). Exits non-zero when drift '
                . 'is found and either --strict is given or '
                . '"extra.composer-assets.fail-on-drift" is true.',
            );
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $composer = $this->requireComposer();
        $io = $this->getIO();

        $format = (string) $input->getOption('format');
        if (!in_array($format, ['text', 'json'], true)) {
            $io->writeError(sprintf('<error>composer-assets: unknown --format "%s" (use "text" or "json").</error>', $format));

            return 1;
        }

        /** @var list<string> $paths */
        $paths = (array) $input->getArgument('paths');
        $only = array_map([self::class, 'normalizePath'], $paths);

        try {
            $handler = new Handler($composer, $io);
            $drifts = $handler->checkDrift($only);
        } catch (\Throwable $e) {
            $io->writeError('<error>composer-assets: ' . $e->getMessage() . '</error>');

            return 1;
        }

        $fail = ($drifts !== []) && ($input->getOption('strict') || $handler->failOnDrift());

        if ($format === 'json') {
            $payload = [
                'count' => count($drifts),
                'failed' => $fail,
                'drift' => array_map(
                    static fn ($drift): array => ['path' => $drift->label(), 'diff' => $drift->diff()],
                    $drifts,
                ),
            ];
            $output->writeln((string) json_encode($payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));

            return $fail ? 1 : 0;
        }

        if ($drifts === []) {
            $io->write('<info>composer-assets: no drift detected.</info>');

            return 0;
        }

        $io->write(sprintf(
            '<comment>composer-assets: %d file(s) have drifted from their package source:</comment>',
            count($drifts),
        ));
        foreach ($drifts as $drift) {
            $io->write('');
            $io->write(sprintf('<info>%s</info>', $drift->label()));
            $io->write(UnifiedDiff::colorize($drift->diff()));
        }

        return $fail ? 1 : 0;
    }
}

