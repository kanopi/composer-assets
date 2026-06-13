<?php

declare(strict_types=1);

namespace Kanopi\Composer\Assets;

/**
 * A resolved absolute path plus the context needed to describe it in messages.
 *
 * Used for both the source (a file inside a providing package) and the
 * destination (a path under the consuming project root).
 */
final class AssetFilePath
{
    public function __construct(
        private readonly string $packageName,
        private readonly string $relativePath,
        private readonly string $fullPath,
    ) {
    }

    /**
     * Builds a destination path resolved relative to the project root.
     */
    public static function destination(string $projectRoot, string $relativePath): self
    {
        return new self('', $relativePath, self::join($projectRoot, $relativePath));
    }

    /**
     * Builds a source path resolved relative to a providing package's root.
     */
    public static function source(string $packageName, string $packageRoot, string $relativePath): self
    {
        return new self($packageName, $relativePath, self::join($packageRoot, $relativePath));
    }

    public function packageName(): string
    {
        return $this->packageName;
    }

    public function relativePath(): string
    {
        return $this->relativePath;
    }

    public function fullPath(): string
    {
        return $this->fullPath;
    }

    public function exists(): bool
    {
        return file_exists($this->fullPath);
    }

    /**
     * Joins a base directory and a relative path with a single separator.
     */
    private static function join(string $base, string $relative): string
    {
        return rtrim($base, '/\\') . '/' . ltrim($relative, '/\\');
    }
}
