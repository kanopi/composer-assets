<?php

declare(strict_types=1);

namespace Kanopi\Composer\Assets;

/**
 * Typed read-only view over a package's `extra.composer-assets` config.
 *
 * Destinations in `file-mapping` are paths relative to the consuming project's
 * root (the directory containing the root composer.json). There is no
 * "locations"/"web-root" indirection: write `web/.htaccess` if your docroot is
 * `web`, or `.htaccess` to target the project root directly.
 */
final class AssetsOptions
{
    /**
     * @param array<string, mixed> $fileMapping
     */
    private function __construct(
        private readonly array $fileMapping,
        private readonly bool $symlink,
        private readonly ?bool $gitignore,
        private readonly array $allowedPackages,
    ) {
    }

    /**
     * Builds options from a raw `extra.composer-assets` array.
     *
     * @param array<string, mixed> $extra
     */
    public static function create(array $extra): self
    {
        $fileMapping = $extra['file-mapping'] ?? [];
        if (!is_array($fileMapping)) {
            throw new \InvalidArgumentException('"composer-assets.file-mapping" must be an object.');
        }

        $allowed = $extra['allowed-packages'] ?? [];
        if (!is_array($allowed)) {
            throw new \InvalidArgumentException('"composer-assets.allowed-packages" must be an array.');
        }

        return new self(
            $fileMapping,
            (bool) ($extra['symlink'] ?? false),
            array_key_exists('gitignore', $extra) ? (bool) $extra['gitignore'] : null,
            array_values(array_map('strval', $allowed)),
        );
    }

    /**
     * @return array<string, mixed> destination path => raw operation value
     */
    public function fileMapping(): array
    {
        return $this->fileMapping;
    }

    public function symlink(): bool
    {
        return $this->symlink;
    }

    /**
     * Returns true/false to force gitignore management, or null to auto-detect.
     */
    public function gitignore(): ?bool
    {
        return $this->gitignore;
    }

    /**
     * @return list<string>
     */
    public function allowedPackages(): array
    {
        return $this->allowedPackages;
    }

    public function hasFileMapping(): bool
    {
        return $this->fileMapping !== [];
    }
}
