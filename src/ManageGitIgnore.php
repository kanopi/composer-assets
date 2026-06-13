<?php

declare(strict_types=1);

namespace Kanopi\Composer\Assets;

use Composer\IO\IOInterface;

/**
 * Adds generated (scaffolded) files to the nearest `.gitignore`.
 *
 * A `.gitignore` is maintained per directory that received managed files: each
 * gets the basenames of the files written into it. Files already covered by an
 * existing ignore rule are left alone.
 */
final class ManageGitIgnore
{
    public function __construct(
        private readonly string $projectRoot,
        private readonly Git $git,
        private readonly IOInterface $io,
    ) {
    }

    /**
     * Decides whether gitignore management should run.
     *
     * @param bool|null $option the `gitignore` config value (null = auto)
     */
    public function isEnabled(?bool $option, string $vendorDir): bool
    {
        if ($option !== null) {
            return $option;
        }

        // Auto: only manage when this is a git repo that ignores its vendor dir
        // (i.e. the project already treats dependency-managed files as ignored).
        return $this->git->isRepository($this->projectRoot)
            && $this->git->isIgnored($vendorDir, $this->projectRoot);
    }

    /**
     * Ensures every managed destination is gitignored.
     *
     * @param list<AssetFilePath> $managed
     */
    public function manage(array $managed): void
    {
        if ($managed === []) {
            return;
        }

        // Group basenames by the directory that holds them.
        $byDir = [];
        foreach ($managed as $path) {
            $full = $path->fullPath();
            $dir = dirname($full);
            $base = basename($full);

            // Respect rules already in place (parent .gitignore, global excludes).
            if ($this->git->isIgnored($path->relativePath(), $this->projectRoot)) {
                continue;
            }
            $byDir[$dir][$base] = $base;
        }

        foreach ($byDir as $dir => $basenames) {
            $this->writeGitignore($dir, array_values($basenames));
        }
    }

    /**
     * @param list<string> $basenames
     */
    private function writeGitignore(string $dir, array $basenames): void
    {
        $gitignore = $dir . '/.gitignore';
        $existing = is_file($gitignore)
            ? preg_split('/\R/', (string) file_get_contents($gitignore)) ?: []
            : [];
        $existingSet = array_flip(array_map('trim', $existing));

        $added = [];
        foreach ($basenames as $base) {
            $entry = '/' . $base;
            if (!isset($existingSet[$entry]) && !isset($existingSet[$base])) {
                $existing[] = $entry;
                $added[] = $entry;
            }
        }

        if ($added === []) {
            return;
        }

        // Normalize trailing newline.
        $contents = rtrim(implode("\n", $existing), "\n") . "\n";
        file_put_contents($gitignore, $contents);

        $this->io->write(sprintf(
            '  - Update <info>%s</info> (%s)',
            $this->relativize($gitignore),
            implode(', ', $added),
        ), true, IOInterface::VERBOSE);
    }

    private function relativize(string $path): string
    {
        $root = rtrim($this->projectRoot, '/\\') . '/';
        if (str_starts_with($path, $root)) {
            return substr($path, strlen($root));
        }

        return $path;
    }
}
