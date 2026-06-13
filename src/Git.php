<?php

declare(strict_types=1);

namespace Kanopi\Composer\Assets;

use Composer\Util\ProcessExecutor;

/**
 * Thin wrapper over the git CLI for the checks gitignore management needs.
 *
 * All methods degrade gracefully (returning false) when git is unavailable or
 * the directory is not a working copy.
 */
final class Git
{
    public function __construct(
        private readonly ProcessExecutor $process,
    ) {
    }

    /**
     * Whether $dir is inside a git working tree.
     */
    public function isRepository(string $dir): bool
    {
        return $this->process->execute('git rev-parse --is-inside-work-tree', $output, $dir) === 0
            && trim($output) === 'true';
    }

    /**
     * Whether $path (relative to $dir) is ignored by git's ignore rules.
     */
    public function isIgnored(string $path, string $dir): bool
    {
        // check-ignore exits 0 when the path IS ignored.
        return $this->process->execute(
            'git check-ignore ' . ProcessExecutor::escape($path),
            $output,
            $dir,
        ) === 0;
    }
}
