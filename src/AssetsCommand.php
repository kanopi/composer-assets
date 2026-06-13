<?php

declare(strict_types=1);

namespace Kanopi\Composer\Assets;

use Composer\Command\BaseCommand;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * `composer assets` — runs scaffolding on demand, independent of install/update.
 */
final class AssetsCommand extends BaseCommand
{
    protected function configure(): void
    {
        $this
            ->setName('assets')
            ->setDescription('Scaffold asset files from allowed packages into the project.')
            ->addOption('no-dev', null, InputOption::VALUE_NONE, 'Run as if dev dependencies were not installed.')
            ->setHelp(
                'Copies, symlinks, or appends files declared under "extra.composer-assets.file-mapping" '
                . 'from allowed packages into the project. Runs automatically after install/update; '
                . 'use this command to re-run on demand.',
            );
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $composer = $this->requireComposer();
        $io = $this->getIO();

        $devMode = !$input->getOption('no-dev');

        try {
            (new Handler($composer, $io))->run($devMode);
        } catch (\Throwable $e) {
            $io->writeError('<error>composer-assets: ' . $e->getMessage() . '</error>');

            return 1;
        }

        return 0;
    }
}
