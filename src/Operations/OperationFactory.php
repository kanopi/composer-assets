<?php

declare(strict_types=1);

namespace Kanopi\Composer\Assets\Operations;

use Kanopi\Composer\Assets\AssetFilePath;
use Kanopi\Composer\Assets\FileMode;

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
        private readonly ?int $defaultMode = null,
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

        // Effective permission mode: per-file "mode", else the global default.
        $mode = $this->mode($destination, $value);

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
     * Resolves a mapping's effective mode: its per-file `"mode"` if present,
     * otherwise the global default passed to this factory.
     *
     * @param array<string, mixed> $value
     */
    private function mode(string $destination, array $value): ?int
    {
        if (!array_key_exists('mode', $value) || $value['mode'] === null) {
            return $this->defaultMode;
        }

        try {
            return FileMode::parse($value['mode']);
        } catch (\InvalidArgumentException $e) {
            throw new \InvalidArgumentException(sprintf('mode for "%s": %s', $destination, $e->getMessage()), 0, $e);
        }
    }
}
