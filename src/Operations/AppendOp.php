<?php

declare(strict_types=1);

namespace Kanopi\Composer\Assets\Operations;

use Composer\IO\IOInterface;
use Kanopi\Composer\Assets\AssetFilePath;

/**
 * Prepends and/or appends content to a destination file.
 *
 * Behavior:
 *  - If the destination is missing and a `default` source is given, that
 *    default is laid down first.
 *  - `prepend` content is added before, `append` content after, the existing
 *    body.
 *  - By default the destination must be a managed (scaffolded) file. Setting
 *    `force-append: true` allows modifying a pre-existing project file, but
 *    only if the content is not already present (so re-runs are idempotent).
 */
final class AppendOp implements OperationInterface
{
    use HasFileMode;

    public function __construct(
        private readonly ?AssetFilePath $prepend = null,
        private readonly ?AssetFilePath $append = null,
        private readonly ?AssetFilePath $default = null,
        private readonly bool $forceAppend = false,
        private readonly bool $managedDefault = false,
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
        $exists = file_exists($destPath);

        // A missing destination with a default available is laid down first; in a
        // dry run we only read the default's bytes (for the body) without writing.
        $defaultBody = null;
        if (!$exists && $this->default && $this->default->exists()) {
            $defaultBody = (string) file_get_contents($this->default->fullPath());
            if (!$dryRun) {
                $this->ensureDirectory(dirname($destPath));
                file_put_contents($destPath, $defaultBody);
                self::applyMode($destPath, $this->mode);
            }
            $exists = true;
        }

        if (!$exists && !$this->forceAppend) {
            $io->write(
                sprintf('  - Skip append <info>%s</info>: target does not exist and no default provided', $label),
                true,
                IOInterface::VERBOSE,
            );

            return false;
        }

        if ($defaultBody !== null) {
            $body = $defaultBody;
        } elseif (file_exists($destPath)) {
            $body = (string) file_get_contents($destPath);
        } else {
            $body = '';
        }
        $prependText = $this->read($this->prepend);
        $appendText = $this->read($this->append);

        // Idempotency guard: don't duplicate content already present.
        if ($prependText !== '' && str_contains($body, $prependText)) {
            $prependText = '';
        }
        if ($appendText !== '' && str_contains($body, $appendText)) {
            $appendText = '';
        }

        if ($prependText === '' && $appendText === '') {
            $io->write(
                sprintf('  - Skip append <info>%s</info>: content already present', $label),
                true,
                IOInterface::VERBOSE,
            );

            return false;
        }

        if ($dryRun) {
            $io->write(sprintf('  - Would append/prepend <info>%s</info>%s', $label, self::modeLabel($this->mode)));

            return true;
        }

        $this->ensureDirectory(dirname($destPath));
        file_put_contents($destPath, $prependText . $body . $appendText);
        self::applyMode($destPath, $this->mode);
        $io->write(sprintf('  - Append/prepend <info>%s</info>%s', $label, self::modeLabel($this->mode)));

        return true;
    }

    public function isManagedFile(): bool
    {
        if ($this->gitignore !== null) {
            return $this->gitignore; // explicit per-file override wins.
        }

        // The target is only ours to gitignore when we laid down its default;
        // a force-appended pre-existing file remains a tracked project file.
        return $this->managedDefault && !$this->forceAppend;
    }

    public function expectedContent(AssetFilePath $destination, ?string $current, bool $globalSymlink): ?string
    {
        // Mirror process()'s computation without writing. Drift here means a run
        // would add or change content (the managed fragment is missing/stale).
        $body = $current;
        if ($body === null && $this->default && $this->default->exists()) {
            $body = (string) file_get_contents($this->default->fullPath());
        }

        if ($body === null && !$this->forceAppend) {
            return null; // a run wouldn't touch a missing, unmanaged target.
        }

        $body ??= '';
        $prependText = $this->read($this->prepend);
        $appendText = $this->read($this->append);

        // Idempotency guard: content already present is not re-added.
        if ($prependText !== '' && str_contains($body, $prependText)) {
            $prependText = '';
        }
        if ($appendText !== '' && str_contains($body, $appendText)) {
            $appendText = '';
        }

        return $prependText . $body . $appendText;
    }

    private function read(?AssetFilePath $path): string
    {
        if ($path === null) {
            return '';
        }
        if (!$path->exists()) {
            throw new \RuntimeException(sprintf(
                'Could not find append/prepend source "%s" in package "%s".',
                $path->relativePath(),
                $path->packageName() ?: '(root)',
            ));
        }

        return (string) file_get_contents($path->fullPath());
    }

    private function ensureDirectory(string $dir): void
    {
        if (!is_dir($dir) && !@mkdir($dir, 0777, true) && !is_dir($dir)) {
            throw new \RuntimeException(sprintf('Could not create directory "%s".', $dir));
        }
    }
}
