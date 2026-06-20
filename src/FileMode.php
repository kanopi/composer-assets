<?php

declare(strict_types=1);

namespace Kanopi\Composer\Assets;

/**
 * Parses a permission mode from config into a chmod-ready integer.
 *
 * Shared by the per-file `"mode"` option (in {@see Operations\OperationFactory})
 * and the global `extra.composer-assets.mode` default (in {@see AssetsOptions}).
 */
final class FileMode
{
    /**
     * Accepts an octal string ("0755", "755", "0o755") or a JSON number whose
     * digits are read as octal (755 => 0755). Returns null when unset; throws
     * on anything that is not a valid octal mode.
     *
     * @param mixed $raw
     */
    public static function parse(mixed $raw): ?int
    {
        if ($raw === null) {
            return null;
        }

        $digits = is_int($raw) ? (string) $raw : (is_string($raw) ? $raw : '');
        $digits = preg_replace('/^0o/i', '', $digits) ?? '';

        if ($digits === '' || preg_match('/^[0-7]{3,4}$/', $digits) !== 1) {
            throw new \InvalidArgumentException(sprintf(
                'Invalid file mode %s: expected an octal string like "0755".',
                var_export($raw, true),
            ));
        }

        return (int) octdec($digits);
    }
}
