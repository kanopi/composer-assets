<?php

declare(strict_types=1);

namespace Kanopi\Composer\Assets\Operations;

use Kanopi\Composer\Assets\AssetFilePath;

/**
 * Normalizes a raw `file-mapping` value into a concrete Operation.
 *
 * Accepted value shapes (mirroring drupal/core-composer-scaffold):
 *   "assets/file"                         => ReplaceOp (copy)
 *   false                                 => SkipOp
 *   { "path": "...", "overwrite": false } => ReplaceOp
 *   { "path": "...", "symlink": true }    => ReplaceOp (symlink)
 *   { "append": "...", "prepend": "...",
 *     "default": "...", "force-append": true } => AppendOp
 *   { "merge": "...", "default": "...", "format": "yaml",
 *     "array": "replace", "force-merge": true }  => MergeOp
 */
final class OperationFactory
{
    public function __construct(
        private readonly string $packageName,
        private readonly string $packageRoot,
    ) {
    }

    /**
     * @param mixed $value the raw value from the file-mapping object
     */
    public function create(string $destination, mixed $value): OperationInterface
    {
        if ($value === false) {
            return new SkipOp();
        }

        if (is_string($value)) {
            return new ReplaceOp($this->src($value));
        }

        if (!is_array($value)) {
            throw new \InvalidArgumentException(sprintf(
                'Invalid file-mapping for "%s": expected string, false, or object.',
                $destination,
            ));
        }

        // Optional per-file gitignore override (null = default behavior).
        $gitignore = array_key_exists('gitignore', $value) ? (bool) $value['gitignore'] : null;

        // Optional per-file permission mode (null = leave the filesystem default).
        $mode = self::parseMode($destination, $value);

        // Structured merge mode (JSON/YAML).
        if (isset($value['merge'])) {
            return new MergeOp(
                $this->src((string) $value['merge']),
                isset($value['default']) ? $this->src((string) $value['default']) : null,
                isset($value['format']) ? (string) $value['format'] : null,
                isset($value['array']) ? (string) $value['array'] : MergeOp::ARRAY_REPLACE,
                (bool) ($value['force-merge'] ?? false),
                isset($value['default']),
                $gitignore,
                $mode,
            );
        }

        // Append/prepend mode.
        if (isset($value['append']) || isset($value['prepend'])) {
            return new AppendOp(
                isset($value['prepend']) ? $this->src((string) $value['prepend']) : null,
                isset($value['append']) ? $this->src((string) $value['append']) : null,
                isset($value['default']) ? $this->src((string) $value['default']) : null,
                (bool) ($value['force-append'] ?? false),
                isset($value['default']),
                $gitignore,
                $mode,
            );
        }

        // Replace mode.
        if (isset($value['path'])) {
            return new ReplaceOp(
                $this->src((string) $value['path']),
                (bool) ($value['overwrite'] ?? true),
                array_key_exists('symlink', $value) ? (bool) $value['symlink'] : null,
                $gitignore,
                $mode,
            );
        }

        throw new \InvalidArgumentException(sprintf(
            'Invalid file-mapping for "%s": object must contain "path", "append", "prepend", or "merge".',
            $destination,
        ));
    }

    private function src(string $relativePath): AssetFilePath
    {
        return AssetFilePath::source($this->packageName, $this->packageRoot, $relativePath);
    }

    /**
     * Parses a per-file "mode" into a chmod-ready integer.
     *
     * Accepts an octal string ("0755", "755", "0o755") or a JSON number whose
     * digits are read as octal (755 => 0755). Returns null when unset.
     *
     * @param array<string, mixed> $value
     */
    private static function parseMode(string $destination, array $value): ?int
    {
        if (!array_key_exists('mode', $value) || $value['mode'] === null) {
            return null;
        }

        $raw = $value['mode'];
        $digits = is_int($raw) ? (string) $raw : (is_string($raw) ? $raw : '');
        $digits = preg_replace('/^0o/i', '', $digits) ?? '';

        if ($digits === '' || preg_match('/^[0-7]{3,4}$/', $digits) !== 1) {
            throw new \InvalidArgumentException(sprintf(
                'Invalid "mode" for "%s": expected an octal string like "0755", got %s.',
                $destination,
                var_export($raw, true),
            ));
        }

        return (int) octdec($digits);
    }
}
