<?php

declare(strict_types=1);

namespace Kanopi\Composer\Assets;

/**
 * Expands path tokens in file-mapping destination keys.
 *
 * Two tokens are supported, both resolving relative to the project root:
 *  - `[project-root]` — the project root itself (an empty prefix).
 *  - `[web-root]` — the docroot, resolved by {@see Handler} from
 *    `extra.composer-assets.web-root`, else Drupal scaffold's
 *    `drupal-scaffold.locations.web-root`, else `wordpress-install-dir`, else the
 *    project root.
 *
 * Letting a recipe package write `[web-root]/.htaccess` means the same mapping
 * targets `web/` on a Drupal project and `public/` on a WordPress one without
 * the consumer redefining the docroot.
 */
final class Tokens
{
    /**
     * @param array<string, string> $replacements token => replacement (already
     *                                             normalized, no trailing slash)
     */
    public function __construct(private readonly array $replacements)
    {
    }

    /**
     * Expands tokens in a destination path and normalizes the result to the
     * project-relative form (forward slashes, no leading/duplicate separators).
     *
     * @throws \InvalidArgumentException on an unknown `[token]`
     */
    public function expand(string $path): string
    {
        $expanded = strtr($path, $this->replacements);
        $expanded = str_replace('\\', '/', $expanded);
        $expanded = preg_replace('#/{2,}#', '/', $expanded) ?? $expanded;
        $expanded = ltrim($expanded, '/');

        // Any token-shaped fragment left over is an unknown/misspelled token.
        if (preg_match('/\[[a-z][a-z-]*\]/', $expanded, $m) === 1) {
            throw new \InvalidArgumentException(sprintf(
                'Unknown token "%s" in path "%s". Known tokens: %s.',
                $m[0],
                $path,
                implode(', ', array_keys($this->replacements)),
            ));
        }

        return $expanded;
    }

    /**
     * Returns the mapping with every key token-expanded (values untouched).
     *
     * @param array<string, mixed> $mapping
     * @return array<string, mixed>
     */
    public function expandKeys(array $mapping): array
    {
        $out = [];
        foreach ($mapping as $key => $value) {
            $out[$this->expand((string) $key)] = $value;
        }

        return $out;
    }
}
