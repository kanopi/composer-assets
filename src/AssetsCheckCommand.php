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
            ->setHelp(
                'Compares each owned destination file ("overwrite": false copies and '
                . '"force-append"/"force-merge" targets) against what its package would '
                . 'produce, printing a unified diff for each divergence. Does not modify '
                . 'any files. Pass one or more paths to limit the report to those files, '
                . 'e.g. "composer assets:check web/robots.txt". Exits non-zero when drift '
                . 'is found and either --strict is given or '
                . '"extra.composer-assets.fail-on-drift" is true.',
            );
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $composer = $this->requireComposer();
        $io = $this->getIO();

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

        $fail = $input->getOption('strict') || $handler->failOnDrift();

        return $fail ? 1 : 0;
    }

    /**
     * Normalizes a user-supplied path to the project-relative form used as
     * file-mapping keys: forward slashes, no leading "./" or "/".
     */
    private static function normalizePath(string $path): string
    {
        $path = str_replace('\\', '/', $path);
        if (str_starts_with($path, './')) {
            $path = substr($path, 2);
        }

        return ltrim($path, '/');
    }
}

