<?php

declare(strict_types=1);

namespace Kanopi\Composer\Assets\Operations;

use Composer\IO\IOInterface;
use Kanopi\Composer\Assets\AssetFilePath;

/**
 * Explicitly does nothing. Produced when a file-mapping value is `false`,
 * letting a downstream package or the root project cancel an inherited mapping.
 */
final class SkipOp implements OperationInterface
{
    public function process(AssetFilePath $destination, IOInterface $io, bool $globalSymlink): bool
    {
        $io->write(
            sprintf('  - Skip <info>%s</info>: disabled', $destination->relativePath()),
            true,
            IOInterface::VERBOSE,
        );

        return false;
    }

    public function isManagedFile(): bool
    {
        return false;
    }

    public function expectedContent(AssetFilePath $destination, ?string $current, bool $globalSymlink): ?string
    {
        return null; // nothing is produced, so nothing can drift.
    }
}
