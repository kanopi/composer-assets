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
    use HasFileMode;

    public function __construct(
        private readonly AssetFilePath $source,
        private readonly bool $overwrite = true,
        private readonly ?bool $symlink = null,
        private readonly ?bool $gitignore = null,
        private readonly ?int $mode = null,
    ) {
    }

    /**
     * The configured permission mode (e.g. 0755), or null for the default.
     */
    public function mode(): ?int
    {
        return $this->mode;
    }

    public function process(AssetFilePath $destination, IOInterface $io, bool $globalSymlink, bool $dryRun = false): bool
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

        $useSymlink = $this->symlink ?? $globalSymlink;

        if ($dryRun) {
            $io->write(sprintf(
                '  - Would %s <info>%s</info> from <comment>%s</comment>%s',
                $useSymlink ? 'symlink' : 'copy',
                $label,
                $this->source->packageName() ?: 'root',
                $useSymlink ? '' : self::modeLabel($this->mode),
            ));

            return true;
        }

        $this->ensureDirectory(dirname($destPath));

        // Clear any existing file/symlink so copy and symlink both behave.
        if (is_link($destPath) || file_exists($destPath)) {
            @unlink($destPath);
        }

        if ($useSymlink) {
            // chmod follows a symlink to its target, which is the package source —
            // so a mode is meaningless (and unsafe) here and is intentionally skipped.
            if ($this->mode !== null) {
                $io->write(sprintf('  - <comment>mode ignored for symlinked %s</comment>', $label), true, IOInterface::VERBOSE);
            }
            $this->symlinkFile($this->source->fullPath(), $destPath);
            $io->write(sprintf('  - Symlink <info>%s</info> from <comment>%s</comment>', $label, $this->source->packageName() ?: 'root'));
        } else {
            if (!@copy($this->source->fullPath(), $destPath)) {
                throw new \RuntimeException(sprintf('Failed to copy to "%s".', $destPath));
            }
            self::applyMode($destPath, $this->mode);
            $io->write(sprintf('  - Copy <info>%s</info> from <comment>%s</comment>%s', $label, $this->source->packageName() ?: 'root', self::modeLabel($this->mode)));
        }

        return true;
    }

    public function kind(): string
    {
        return 'replace';
    }

    public function isManagedFile(): bool
    {
        // A replaced file is generated, so it is gitignored by default; a
        // per-file `gitignore: false` keeps it tracked (e.g. .circleci/config.yml,
        // which CI only runs when committed).
        return $this->gitignore ?? true;
    }

    public function gitignoreIntent(): ?bool
    {
        return $this->gitignore;
    }

    public function expectedContent(AssetFilePath $destination, ?string $current, bool $globalSymlink): ?string
    {
        // Both `overwrite: true` and `overwrite: false` copies are drift-checked:
        // a divergence means the on-disk file differs from the package source —
        // for an owned (overwrite:false) copy the package has moved ahead, and for
        // an overwrite:true copy the generated file has been hand-edited (and would
        // be clobbered on the next run). Only symlinks are exempt: the link *is*
        // the source, so it can never diverge.
        if ($this->symlink ?? $globalSymlink) {
            return null;
        }

        if (!$this->source->exists()) {
            return null;
        }

        return (string) file_get_contents($this->source->fullPath());
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
