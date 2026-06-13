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

        // Structured merge mode (JSON/YAML).
        if (isset($value['merge'])) {
            return new MergeOp(
                $this->src((string) $value['merge']),
                isset($value['default']) ? $this->src((string) $value['default']) : null,
                isset($value['format']) ? (string) $value['format'] : null,
                isset($value['array']) ? (string) $value['array'] : MergeOp::ARRAY_REPLACE,
                (bool) ($value['force-merge'] ?? false),
                isset($value['default']),
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
            );
        }

        // Replace mode.
        if (isset($value['path'])) {
            return new ReplaceOp(
                $this->src((string) $value['path']),
                (bool) ($value['overwrite'] ?? true),
                array_key_exists('symlink', $value) ? (bool) $value['symlink'] : null,
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
}
