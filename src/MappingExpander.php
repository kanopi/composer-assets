<?php

declare(strict_types=1);

namespace Kanopi\Composer\Assets;

use Composer\IO\IOInterface;

/**
 * Expands directory and glob `replace` mappings into concrete per-file mappings.
 *
 * A single entry whose source is a **directory** or a **glob pattern** is blown
 * out into one entry per matched file, so the rest of the pipeline (override
 * precedence, drift, gitignore, mode) keeps working on a flat, single-file
 * mapping exactly as before.
 *
 *   ".github/": "assets/github/"          // directory: recursive, keeps structure
 *   ".github/workflows/": "assets/ci/*.yml" // glob: single level, flattened by basename
 *
 * Only replace-type entries expand (a plain string, or an object with `path`).
 * Append/prepend/merge/skip entries are passed through untouched. Sibling
 * options on a `path` object (`overwrite`, `symlink`, `gitignore`, `mode`) are
 * copied onto every expanded entry.
 */
final class MappingExpander
{
    public function __construct(
        private readonly IOInterface $io,
    ) {
    }

    /**
     * @param array<string, mixed> $mapping destination => raw operation value
     * @return array<string, mixed> the mapping with directory/glob entries expanded
     */
    public function expand(array $mapping, string $packageRoot): array
    {
        $out = [];
        foreach ($mapping as $destination => $value) {
            $destination = (string) $destination;
            $source = $this->expandableSource($value);

            if ($source === null) {
                $out[$destination] = $value; // not a replace-type entry.
                continue;
            }

            if ($this->isGlob($source)) {
                $this->merge($out, $this->expandGlob($destination, $value, $source, $packageRoot));
                continue;
            }

            $sourceDir = $packageRoot . '/' . rtrim($source, '/');
            if (is_dir($sourceDir)) {
                $this->merge($out, $this->expandDirectory($destination, $value, $source, $packageRoot));
                continue;
            }

            $out[$destination] = $value; // ordinary single file.
        }

        return $out;
    }

    /**
     * The source path of an expandable (replace-type) entry, or null.
     *
     * @param mixed $value
     */
    private function expandableSource(mixed $value): ?string
    {
        if (is_string($value)) {
            return $value;
        }
        if (is_array($value) && isset($value['path']) && is_string($value['path'])) {
            return $value['path'];
        }

        return null;
    }

    private function isGlob(string $source): bool
    {
        return preg_match('/[*?\[]/', $source) === 1;
    }

    /**
     * @param mixed $value
     * @return array<string, mixed>
     */
    private function expandGlob(string $destination, mixed $value, string $pattern, string $packageRoot): array
    {
        $matches = glob($packageRoot . '/' . $pattern, GLOB_NOSORT) ?: [];
        $files = array_filter($matches, 'is_file');

        if ($files === []) {
            $this->io->writeError(sprintf(
                '<warning>composer-assets: glob "%s" matched no files; skipping.</warning>',
                $pattern,
            ));

            return [];
        }

        $prefixLen = strlen($packageRoot) + 1;
        $out = [];
        foreach ($files as $abs) {
            $sourceRel = str_replace('\\', '/', substr($abs, $prefixLen));
            $out[$this->joinDest($destination, basename($abs))] = $this->withPath($value, $sourceRel);
        }
        ksort($out);

        return $out;
    }

    /**
     * @param mixed $value
     * @return array<string, mixed>
     */
    private function expandDirectory(string $destination, mixed $value, string $source, string $packageRoot): array
    {
        $sourceDir = rtrim($source, '/');
        $base = $packageRoot . '/' . $sourceDir;
        $baseLen = strlen($base) + 1;
        $rootLen = strlen($packageRoot) + 1;

        $iterator = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($base, \FilesystemIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::LEAVES_ONLY,
        );

        $out = [];
        /** @var \SplFileInfo $file */
        foreach ($iterator as $file) {
            if (!$file->isFile()) {
                continue;
            }
            $abs = $file->getPathname();
            $destRel = str_replace('\\', '/', substr($abs, $baseLen));
            $sourceRel = str_replace('\\', '/', substr($abs, $rootLen));
            $out[$this->joinDest($destination, $destRel)] = $this->withPath($value, $sourceRel);
        }
        ksort($out);

        return $out;
    }

    /**
     * Returns $value with its source path replaced, preserving every other
     * option (overwrite/symlink/gitignore/mode).
     *
     * @param mixed $value
     * @return string|array<string, mixed>
     */
    private function withPath(mixed $value, string $sourceRel): string|array
    {
        if (is_array($value)) {
            $value['path'] = $sourceRel;

            return $value;
        }

        return $sourceRel;
    }

    private function joinDest(string $destination, string $relative): string
    {
        $destination = rtrim($destination, '/');

        return $destination === '' ? $relative : $destination . '/' . $relative;
    }

    /**
     * Merges expanded entries into the accumulator (later entries win on a key
     * collision, matching the rest of the pipeline).
     *
     * @param array<string, mixed> $out
     * @param array<string, mixed> $expanded
     */
    private function merge(array &$out, array $expanded): void
    {
        foreach ($expanded as $key => $value) {
            $out[$key] = $value;
        }
    }
}
