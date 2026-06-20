<?php

declare(strict_types=1);

namespace Kanopi\Composer\Assets;

use Composer\Command\BaseCommand;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * `composer assets:status` — lists every managed file with the package that
 * provides it, the operation that produces it, and its current state.
 *
 * Read-only. A quick inventory/debugging view: which files this plugin manages,
 * where each comes from, and whether any owned file has drifted.
 */
final class AssetsStatusCommand extends BaseCommand
{
    use NormalizesPaths;

    protected function configure(): void
    {
        $this
            ->setName('assets:status')
            ->setDescription('List managed files with their provider, operation, and state.')
            ->addArgument(
                'paths',
                InputArgument::IS_ARRAY | InputArgument::OPTIONAL,
                'Limit the listing to these destination paths (project-relative). Default: all managed files.',
            )
            ->setHelp(
                'Prints a table of every file declared in "file-mapping" (across all '
                . 'allowed packages): the destination, the providing package, the '
                . 'operation (replace/append/merge/skip), and its state — "in sync", '
                . '"drifted", "missing", or "skipped". Writes nothing.',
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
            $rows = (new Handler($composer, $io))->status($only);
        } catch (\Throwable $e) {
            $io->writeError('<error>composer-assets: ' . $e->getMessage() . '</error>');

            return 1;
        }

        if ($rows === []) {
            $io->write('<info>composer-assets: no managed files.</info>');

            return 0;
        }

        $wPath = strlen('File');
        $wProvider = strlen('Provider');
        $wOperation = strlen('Operation');
        foreach ($rows as $row) {
            $wPath = max($wPath, strlen($row['path']));
            $wProvider = max($wProvider, strlen($row['provider']));
            $wOperation = max($wOperation, strlen($row['operation']));
        }
        $format = "%-{$wPath}s  %-{$wProvider}s  %-{$wOperation}s  %s";

        $io->write(sprintf($format, 'File', 'Provider', 'Operation', 'State'));
        $io->write(sprintf($format, str_repeat('-', $wPath), str_repeat('-', $wProvider), str_repeat('-', $wOperation), '-----'));

        $counts = [];
        foreach ($rows as $row) {
            $counts[$row['state']] = ($counts[$row['state']] ?? 0) + 1;
            $io->write(sprintf(
                $format,
                $row['path'],
                $row['provider'],
                $row['operation'],
                self::colorState($row['state']),
            ));
        }

        $io->write('');
        $io->write(sprintf('<info>composer-assets: %d managed file(s)%s.</info>', count($rows), self::summary($counts)));

        return 0;
    }

    private static function colorState(string $state): string
    {
        return match ($state) {
            'in sync' => '<info>in sync</info>',
            'drifted' => '<comment>drifted</comment>',
            'missing' => '<comment>missing</comment>',
            default => $state,
        };
    }

    /**
     * @param array<string, int> $counts
     */
    private static function summary(array $counts): string
    {
        if ($counts === []) {
            return '';
        }
        ksort($counts);
        $parts = [];
        foreach ($counts as $state => $n) {
            $parts[] = sprintf('%d %s', $n, $state);
        }

        return ' (' . implode(', ', $parts) . ')';
    }
}
