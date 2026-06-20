<?php

declare(strict_types=1);

namespace Kanopi\Composer\Assets\Drift;

use Composer\IO\IOInterface;
use Kanopi\Composer\Assets\AssetFileInfo;

/**
 * Compares each owned destination file against the content its providing package
 * would produce, collecting the divergences.
 *
 * Only "owned" files can drift — those a run does not unconditionally rewrite:
 * `overwrite: false` copies, and `force-append` / `force-merge` targets. A
 * missing destination is a "would create", not drift, so it is skipped here.
 */
final class DriftChecker
{
    public function __construct(
        private readonly IOInterface $io,
    ) {
    }

    /**
     * @param iterable<AssetFileInfo> $mappings
     * @param list<string> $only limit the check to these destination paths
     *                           (project-relative, matching file-mapping keys);
     *                           empty checks every owned file.
     * @return list<Drift>
     */
    public function check(iterable $mappings, bool $globalSymlink, array $only = []): array
    {
        $infos = is_array($mappings) ? $mappings : iterator_to_array($mappings);

        if ($only !== []) {
            $known = [];
            foreach ($infos as $info) {
                $known[$info->destination()->relativePath()] = true;
            }
            foreach ($only as $path) {
                if (!isset($known[$path])) {
                    $this->io->writeError(sprintf(
                        '<warning>composer-assets: "%s" is not a managed file; skipping.</warning>',
                        $path,
                    ));
                }
            }
            $infos = array_filter(
                $infos,
                static fn (AssetFileInfo $info): bool => in_array($info->destination()->relativePath(), $only, true),
            );
        }

        $drifts = [];

        foreach ($infos as $info) {
            if (!$info->driftCheck()) {
                continue;
            }

            $destination = $info->destination();
            $current = $destination->exists()
                ? (string) file_get_contents($destination->fullPath())
                : null;

            try {
                $expected = $info->operation()->expectedContent($destination, $current, $globalSymlink);
            } catch (\Throwable $e) {
                $this->io->writeError(sprintf(
                    '<warning>composer-assets: could not drift-check %s: %s</warning>',
                    $destination->relativePath(),
                    $e->getMessage(),
                ));

                continue;
            }

            // null = not an owned/checkable file; missing on disk = would-create,
            // not drift; identical = in sync.
            if ($expected === null || $current === null || $current === $expected) {
                continue;
            }

            $drifts[] = new Drift($destination->relativePath(), $destination->fullPath(), $current, $expected);
        }

        return $drifts;
    }
}
