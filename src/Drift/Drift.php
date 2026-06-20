<?php

declare(strict_types=1);

namespace Kanopi\Composer\Assets\Drift;

/**
 * One detected divergence between a destination file on disk and the content
 * the providing package would produce for it.
 */
final class Drift
{
    public function __construct(
        private readonly string $label,
        private readonly string $fullPath,
        private readonly string $current,
        private readonly string $expected,
    ) {
    }

    /**
     * Destination path, relative to the project root (e.g. "web/.htaccess").
     */
    public function label(): string
    {
        return $this->label;
    }

    /**
     * Absolute path to the drifted file on disk.
     */
    public function fullPath(): string
    {
        return $this->fullPath;
    }

    /**
     * The bytes currently on disk.
     */
    public function current(): string
    {
        return $this->current;
    }

    /**
     * The bytes a real scaffolding run would produce.
     */
    public function expected(): string
    {
        return $this->expected;
    }

    /**
     * A unified diff from the on-disk content (-) to the expected content (+).
     */
    public function diff(): string
    {
        return UnifiedDiff::render($this->current, $this->expected);
    }

    /**
     * Overwrites the on-disk file with the expected (package-produced) content,
     * resolving the drift. Returns false if the write failed.
     */
    public function apply(): bool
    {
        return @file_put_contents($this->fullPath, $this->expected) !== false;
    }
}
