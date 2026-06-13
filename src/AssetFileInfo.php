<?php

declare(strict_types=1);

namespace Kanopi\Composer\Assets;

use Composer\IO\IOInterface;
use Kanopi\Composer\Assets\Operations\OperationInterface;

/**
 * Binds a destination path to the operation that produces it.
 *
 * One of these exists per unique destination after all packages' file-mappings
 * have been merged (later packages override earlier ones for the same key).
 */
final class AssetFileInfo
{
    public function __construct(
        private readonly AssetFilePath $destination,
        private readonly OperationInterface $operation,
    ) {
    }

    public function destination(): AssetFilePath
    {
        return $this->destination;
    }

    public function operation(): OperationInterface
    {
        return $this->operation;
    }

    /**
     * Runs the operation. Returns true when a managed file was written (and
     * thus is a candidate for gitignore management).
     */
    public function process(IOInterface $io, bool $globalSymlink): bool
    {
        $changed = $this->operation->process($this->destination, $io, $globalSymlink);

        return $changed && $this->operation->isManagedFile();
    }
}
