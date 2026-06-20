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
 * `composer assets:reapply` — overwrites owned project files that have drifted
 * with the content their providing package would produce.
 *
 * Where `assets:check` only reports drift, this resolves it: for each drifted
 * owned file (`overwrite: false` copies, `force-append` / `force-merge`
 * targets) it shows the diff and, after confirmation, re-applies the package
 * content. Pass paths to limit it to specific files, and `--yes` to accept
 * every change without prompting.
 *
 * Files that are merely *missing* are not handled here (drift detection treats
 * a missing destination as a "would create", not drift) — run `composer assets`
 * to scaffold those.
 */
final class AssetsReapplyCommand extends BaseCommand
{
    use NormalizesPaths;

    protected function configure(): void
    {
        $this
            ->setName('assets:reapply')
            ->setDescription('Overwrite drifted owned files with the content their package would produce.')
            ->addArgument(
                'paths',
                InputArgument::IS_ARRAY | InputArgument::OPTIONAL,
                'Limit the re-apply to these destination paths (project-relative, matching the file-mapping keys). Default: all drifted files.',
            )
            ->addOption('yes', 'y', InputOption::VALUE_NONE, 'Apply every change without prompting for confirmation.')
            ->addOption('dry-run', null, InputOption::VALUE_NONE, 'Show what would be re-applied without writing or prompting.')
            ->setHelp(
                'Resolves drift reported by "composer assets:check" by overwriting each '
                . 'owned destination file ("overwrite": false copies and '
                . '"force-append"/"force-merge" targets) with what its package would '
                . 'produce. Shows the diff for each file and asks before writing; pass '
                . '--yes (-y) to accept all without prompting, --dry-run to preview '
                . 'without writing, or one or more paths to limit which files are '
                . 're-applied, e.g. "composer assets:reapply web/robots.txt". Missing '
                . 'files are not created here — run "composer assets" for that.',
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
            $drifts = (new Handler($composer, $io))->checkDrift($only);
        } catch (\Throwable $e) {
            $io->writeError('<error>composer-assets: ' . $e->getMessage() . '</error>');

            return 1;
        }

        if ($drifts === []) {
            $io->write('<info>composer-assets: no drift detected; nothing to reapply.</info>');

            return 0;
        }

        $assumeYes = (bool) $input->getOption('yes');
        $dryRun = (bool) $input->getOption('dry-run');

        if ($dryRun) {
            $io->write('<comment>composer-assets: dry run — no files will be written.</comment>');
        }
        $io->write(sprintf(
            '<comment>composer-assets: %d file(s) have drifted from their package source:</comment>',
            count($drifts),
        ));

        $applied = 0;
        foreach ($drifts as $drift) {
            $io->write('');
            $io->write(sprintf('<info>%s</info>', $drift->label()));
            $io->write(UnifiedDiff::colorize($drift->diff()));

            if ($dryRun) {
                $io->write(sprintf('  - Would reapply <info>%s</info>.', $drift->label()));
                $applied++;

                continue;
            }

            if (!$assumeYes && !$io->askConfirmation(sprintf('  Overwrite %s? [y/N] ', $drift->label()), false)) {
                $io->write('  - <comment>Skipped.</comment>');

                continue;
            }

            if (!$drift->apply()) {
                $io->writeError(sprintf('  - <error>Failed to write %s.</error>', $drift->label()));

                continue;
            }

            $io->write(sprintf('  - Reapplied <info>%s</info>.', $drift->label()));
            $applied++;
        }

        $io->write('');
        $io->write(sprintf(
            $dryRun
                ? '<info>composer-assets: %d of %d file(s) would be reapplied (dry run; nothing written).</info>'
                : '<info>composer-assets: reapplied %d of %d file(s).</info>',
            $applied,
            count($drifts),
        ));

        return 0;
    }
}
