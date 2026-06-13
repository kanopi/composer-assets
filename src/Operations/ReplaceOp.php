<?php

declare(strict_types=1);

namespace Kanopi\Composer\Assets\Operations;

use Composer\IO\IOInterface;
use Kanopi\Composer\Assets\AssetFilePath;

/**
 * Copies (or symlinks) a source file from a providing package to a destination.
 *
 * Honors `overwrite: false` to protect a pre-existing destination, and a
 * per-file or global `symlink` flag to link instead of copy.
 */
final class ReplaceOp implements OperationInterface
{
    public function __construct(
        private readonly AssetFilePath $source,
        private readonly bool $overwrite = true,
        private readonly ?bool $symlink = null,
        private readonly ?bool $gitignore = null,
    ) {
    }

    public function process(AssetFilePath $destination, IOInterface $io, bool $globalSymlink): bool
    {
        $destPath = $destination->fullPath();
        $label = $destination->relativePath();

        if (!$this->source->exists()) {
            throw new \RuntimeException(sprintf(
                'Could not find source file "%s" in package "%s".',
                $this->source->relativePath(),
                $this->source->packageName() ?: '(root)',
            ));
        }

        if (!$this->overwrite && file_exists($destPath)) {
            $io->write(
                sprintf('  - Keep <info>%s</info>: already exists, overwrite disabled', $label),
                true,
                IOInterface::VERBOSE,
            );

            return false;
        }

        $this->ensureDirectory(dirname($destPath));

        // Clear any existing file/symlink so copy and symlink both behave.
        if (is_link($destPath) || file_exists($destPath)) {
            @unlink($destPath);
        }

        $useSymlink = $this->symlink ?? $globalSymlink;
        if ($useSymlink) {
            $this->symlinkFile($this->source->fullPath(), $destPath);
            $io->write(sprintf('  - Symlink <info>%s</info> from <comment>%s</comment>', $label, $this->source->packageName() ?: 'root'));
        } else {
            if (!@copy($this->source->fullPath(), $destPath)) {
                throw new \RuntimeException(sprintf('Failed to copy to "%s".', $destPath));
            }
            $io->write(sprintf('  - Copy <info>%s</info> from <comment>%s</comment>', $label, $this->source->packageName() ?: 'root'));
        }

        return true;
    }

    public function isManagedFile(): bool
    {
        // A replaced file is generated, so it is gitignored by default; a
        // per-file `gitignore: false` keeps it tracked (e.g. .circleci/config.yml,
        // which CI only runs when committed).
        return $this->gitignore ?? true;
    }

    private function ensureDirectory(string $dir): void
    {
        if (!is_dir($dir) && !@mkdir($dir, 0777, true) && !is_dir($dir)) {
            throw new \RuntimeException(sprintf('Could not create directory "%s".', $dir));
        }
    }

    private function symlinkFile(string $target, string $link): void
    {
        // Prefer a relative symlink so the project stays portable.
        $relative = self::relativePath(dirname($link), $target);
        if (!@symlink($relative, $link) && !@symlink($target, $link)) {
            throw new \RuntimeException(sprintf('Failed to symlink "%s" to "%s".', $link, $target));
        }
    }

    /**
     * Computes a relative path from $from (a directory) to $to.
     */
    private static function relativePath(string $from, string $to): string
    {
        $fromParts = explode('/', str_replace('\\', '/', rtrim($from, '/')));
        $toParts = explode('/', str_replace('\\', '/', $to));

        $i = 0;
        while (isset($fromParts[$i], $toParts[$i]) && $fromParts[$i] === $toParts[$i]) {
            $i++;
        }

        $up = array_fill(0, count($fromParts) - $i, '..');
        $down = array_slice($toParts, $i);

        return implode('/', [...$up, ...$down]) ?: '.';
    }
}
