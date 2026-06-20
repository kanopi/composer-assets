<?php

declare(strict_types=1);

namespace Kanopi\Composer\Assets\Operations;

/**
 * Applies an optional per-file permission mode to a written destination.
 *
 * Shared by the operations that write files (replace/append/merge). The mode is
 * the integer parsed by {@see OperationFactory} from an octal string such as
 * "0755"; null means "leave the filesystem default".
 */
trait HasFileMode
{
    /**
     * Chmods $path to $mode when a mode is configured. Best-effort: a failure
     * (e.g. unsupported filesystem) is silently ignored, like the copy/symlink
     * paths elsewhere.
     */
    private static function applyMode(string $path, ?int $mode): void
    {
        if ($mode !== null) {
            @chmod($path, $mode);
        }
    }

    /**
     * A " (mode 0755)" suffix for log lines, or "" when no mode is configured.
     */
    private static function modeLabel(?int $mode): string
    {
        return $mode === null ? '' : sprintf(' (mode %04o)', $mode);
    }
}
