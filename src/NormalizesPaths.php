<?php

declare(strict_types=1);

namespace Kanopi\Composer\Assets;

/**
 * Shared path normalization for commands that accept destination paths as
 * arguments (matching the `file-mapping` keys).
 */
trait NormalizesPaths
{
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
