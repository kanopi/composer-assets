<?php

declare(strict_types=1);

namespace Kanopi\Composer\Assets\Operations;

use Composer\IO\IOInterface;
use Kanopi\Composer\Assets\AssetFilePath;

/**
 * A single action applied to one destination path (replace, append, skip).
 */
interface OperationInterface
{
    /**
     * Performs the operation, writing to $destination on disk.
     *
     * @return bool true if the destination file was written/changed.
     */
    public function process(AssetFilePath $destination, IOInterface $io, bool $globalSymlink): bool;

    /**
     * Whether this operation contributes a file that should be gitignored.
     *
     * Append operations against pre-existing (non-scaffolded) files do not, as
     * the target is a tracked project file rather than a generated one.
     */
    public function isManagedFile(): bool;

    /**
     * The content a real run would settle the destination on, computed WITHOUT
     * writing to disk — used for drift detection.
     *
     * Returns null when this operation owns no stable, checkable destination:
     * a plain `overwrite: true` copy or a symlink (both re-synced every run, so
     * they never drift), a skip, or a non-idempotent merge (`array: concat`,
     * whose result grows on every run and would always read as drift).
     *
     * @param string|null $current the destination's current bytes, or null if absent
     */
    public function expectedContent(AssetFilePath $destination, ?string $current, bool $globalSymlink): ?string;
}
