<?php

declare(strict_types=1);

namespace Kanopi\Composer\Assets;

use Composer\IO\IOInterface;

/**
 * Maintains the nearest `.gitignore` for scaffolded files.
 *
 * A `.gitignore` is managed per directory that holds managed files: generated
 * files get their basename added, and files explicitly opted out
 * (`gitignore: false`) have any entry the plugin previously wrote **removed**, so
 * flipping a file to tracked retracts its stale ignore line. A `.gitignore` left
 * empty by a removal is deleted. Lines for files the plugin does not manage are
 * never touched.
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
     * Adds managed files to, and retracts opted-out files from, the nearest
     * `.gitignore`.
     *
     * @param list<AssetFilePath> $toIgnore   files to ensure are ignored
     * @param list<AssetFilePath> $toUnignore files to ensure are NOT ignored
     */
    public function manage(array $toIgnore, array $toUnignore): void
    {
        // dir => ['add' => [base => true], 'remove' => [base => true]]
        $byDir = [];

        foreach ($toIgnore as $path) {
            // Respect rules already in place (parent .gitignore, global excludes).
            if ($this->git->isIgnored($path->relativePath(), $this->projectRoot)) {
                continue;
            }
            $byDir[dirname($path->fullPath())]['add'][basename($path->fullPath())] = true;
        }

        foreach ($toUnignore as $path) {
            $byDir[dirname($path->fullPath())]['remove'][basename($path->fullPath())] = true;
        }

        foreach ($byDir as $dir => $ops) {
            $this->rewriteGitignore(
                $dir,
                array_keys($ops['add'] ?? []),
                array_keys($ops['remove'] ?? []),
            );
        }
    }

    /**
     * Reads the directory's `.gitignore`, removes entries for $remove basenames,
     * adds missing entries for $add basenames, and writes it back (deleting the
     * file if nothing meaningful remains). Only the plugin's own entries are
     * touched; other lines are preserved verbatim.
     *
     * @param list<string> $add
     * @param list<string> $remove
     */
    private function rewriteGitignore(string $dir, array $add, array $remove): void
    {
        $gitignore = $dir . '/.gitignore';
        $lines = is_file($gitignore)
            ? preg_split('/\R/', (string) file_get_contents($gitignore)) ?: []
            : [];

        // Lines matching an opted-out file ("/base" or "base") are dropped.
        $removeSet = [];
        foreach ($remove as $base) {
            $removeSet['/' . $base] = true;
            $removeSet[$base] = true;
        }

        $kept = [];
        $removed = [];
        foreach ($lines as $line) {
            $trimmed = trim($line);
            if ($trimmed !== '' && isset($removeSet[$trimmed])) {
                $removed[] = $trimmed;

                continue;
            }
            $kept[] = $line;
        }

        $present = array_flip(array_map('trim', $kept));
        $added = [];
        foreach ($add as $base) {
            $entry = '/' . $base;
            if (!isset($present[$entry]) && !isset($present[$base])) {
                $kept[] = $entry;
                $added[] = $entry;
            }
        }

        if ($added === [] && $removed === []) {
            return;
        }

        // If the removal emptied the file (no non-blank lines), delete it.
        $hasContent = false;
        foreach ($kept as $line) {
            if (trim($line) !== '') {
                $hasContent = true;
                break;
            }
        }

        if (!$hasContent) {
            if (is_file($gitignore)) {
                @unlink($gitignore);
                $this->io->write(sprintf('  - Remove <info>%s</info> (now empty)', $this->relativize($gitignore)), true, IOInterface::VERBOSE);
            }

            return;
        }

        file_put_contents($gitignore, rtrim(implode("\n", $kept), "\n") . "\n");

        $changes = array_merge(
            array_map(static fn (string $e): string => '+' . $e, $added),
            array_map(static fn (string $e): string => '-' . $e, $removed),
        );
        $this->io->write(sprintf(
            '  - Update <info>%s</info> (%s)',
            $this->relativize($gitignore),
            implode(', ', $changes),
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
