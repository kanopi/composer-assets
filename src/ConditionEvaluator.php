<?php

declare(strict_types=1);

namespace Kanopi\Composer\Assets;

use Composer\Semver\Semver;

/**
 * Evaluates `if` / `unless` conditions on mappings and resolves them into a flat
 * single-value mapping the rest of the pipeline understands.
 *
 * Two mapping shapes carry conditions:
 *  - a single object with `if` / `unless` — kept when it passes, else dropped;
 *  - an **ordered candidate list** (a JSON array) — the first candidate whose
 *    condition passes wins; a condition-less candidate is the fallback.
 *
 * Supported conditions (object keys, AND-ed together):
 *  - `package`: "vendor/name" (present) or "vendor/name:^10" (present + version).
 *  - `php`:     a version constraint, e.g. ">=8.2".
 *  - `env`:     "NAME" (set & non-empty) or { "NAME": "value" } (equals).
 *  - `exists`:  a project-relative path that must exist.
 *
 * `env` and `exists` are environment-dependent (the same repo can resolve
 * differently on different machines); `package` / `php` are reproducible from
 * the lock. `exists` is evaluated against pre-run disk state.
 */
final class ConditionEvaluator
{
    private readonly \stdClass $omit;

    /**
     * @param array<string, string> $installedVersions package name => pretty version
     * @param array<string, string|false> $env environment variables (name => value)
     */
    public function __construct(
        private readonly array $installedVersions,
        private readonly string $phpVersion,
        private readonly array $env,
        private readonly string $projectRoot,
    ) {
        $this->omit = new \stdClass();
    }

    /**
     * Resolves a raw file-mapping: drops entries whose condition fails, picks the
     * winning candidate from a list, and strips the `if` / `unless` keys.
     *
     * @param array<string, mixed> $mapping
     * @return array<string, mixed>
     */
    public function resolve(array $mapping): array
    {
        $out = [];
        foreach ($mapping as $destination => $value) {
            $resolved = $this->resolveValue($value);
            if ($resolved !== $this->omit) {
                $out[(string) $destination] = $resolved;
            }
        }

        return $out;
    }

    /**
     * Merges condition groups into the base mapping.
     *
     * Each group is `{ "if"|"unless": {...}, "file-mapping": {...} }`. Groups
     * whose condition holds have their file-mapping merged over the base, in
     * order — so a later passing group (or the base) is overridden last-wins by
     * destination key. The result is then handed to {@see resolve()}.
     *
     * @param array<string, mixed> $base
     * @param list<mixed> $groups
     * @return array<string, mixed>
     */
    public function mergeConditionalGroups(array $base, array $groups): array
    {
        $merged = $base;
        foreach ($groups as $index => $group) {
            if (!is_array($group) || array_is_list($group)) {
                throw new \InvalidArgumentException(sprintf('"conditional[%s]" must be a group object.', $index));
            }

            if (isset($group['if']) && !$this->passes($this->asCondition($group['if'], 'if'))) {
                continue;
            }
            if (isset($group['unless']) && $this->passes($this->asCondition($group['unless'], 'unless'))) {
                continue;
            }

            $mapping = $group['file-mapping'] ?? null;
            if (!is_array($mapping)) {
                throw new \InvalidArgumentException(sprintf(
                    '"conditional[%s]" must contain a "file-mapping" object.',
                    $index,
                ));
            }

            foreach ($mapping as $destination => $value) {
                $merged[(string) $destination] = $value;
            }
        }

        return $merged;
    }

    /**
     * Whether a single condition object holds (all keys must pass).
     *
     * @param array<string, mixed> $condition
     */
    public function passes(array $condition): bool
    {
        foreach ($condition as $type => $arg) {
            $ok = match ($type) {
                'package' => $this->packageMatches((string) $arg),
                'php' => Semver::satisfies($this->phpVersion, (string) $arg),
                'env' => $this->envMatches($arg),
                'exists' => $this->pathExists((string) $arg),
                default => throw new \InvalidArgumentException(sprintf(
                    'Unknown condition "%s" (expected "package", "php", "env", or "exists").',
                    $type,
                )),
            };
            if (!$ok) {
                return false;
            }
        }

        return true;
    }

    /**
     * @param mixed $value
     * @return mixed the resolved value, or the omit sentinel when nothing applies
     */
    private function resolveValue(mixed $value): mixed
    {
        // Candidate list: first passing candidate wins.
        if (is_array($value) && array_is_list($value)) {
            foreach ($value as $candidate) {
                if ($this->candidatePasses($candidate)) {
                    return $this->stripConditions($candidate);
                }
            }

            return $this->omit;
        }

        // Single object that may carry if/unless.
        if (is_array($value)) {
            return $this->candidatePasses($value) ? $this->stripConditions($value) : $this->omit;
        }

        // Plain string or false: unconditional.
        return $value;
    }

    /**
     * @param mixed $candidate
     */
    private function candidatePasses(mixed $candidate): bool
    {
        // A string / false candidate (or anything not an object) carries no
        // condition and always applies — it acts as the fallback.
        if (!is_array($candidate) || array_is_list($candidate)) {
            return true;
        }

        if (isset($candidate['if']) && !$this->passes($this->asCondition($candidate['if'], 'if'))) {
            return false;
        }
        if (isset($candidate['unless']) && $this->passes($this->asCondition($candidate['unless'], 'unless'))) {
            return false;
        }

        return true;
    }

    /**
     * @param mixed $value
     * @return mixed
     */
    private function stripConditions(mixed $value): mixed
    {
        if (is_array($value)) {
            unset($value['if'], $value['unless']);
        }

        return $value;
    }

    /**
     * @param mixed $condition
     * @return array<string, mixed>
     */
    private function asCondition(mixed $condition, string $key): array
    {
        if (!is_array($condition) || array_is_list($condition)) {
            throw new \InvalidArgumentException(sprintf('"%s" must be a condition object.', $key));
        }

        return $condition;
    }

    private function packageMatches(string $spec): bool
    {
        $pos = strpos($spec, ':');
        $name = $pos === false ? $spec : substr($spec, 0, $pos);
        $constraint = $pos === false ? '' : substr($spec, $pos + 1);

        if (!isset($this->installedVersions[$name])) {
            return false;
        }

        return $constraint === '' || Semver::satisfies($this->installedVersions[$name], $constraint);
    }

    /**
     * @param mixed $arg
     */
    private function envMatches(mixed $arg): bool
    {
        if (is_string($arg)) {
            $value = $this->env[$arg] ?? false;

            return $value !== false && $value !== '';
        }

        if (is_array($arg) && !array_is_list($arg)) {
            foreach ($arg as $name => $expected) {
                if (($this->env[$name] ?? null) !== (string) $expected) {
                    return false;
                }
            }

            return true;
        }

        throw new \InvalidArgumentException('"env" condition must be a string or an object of name => value.');
    }

    private function pathExists(string $relative): bool
    {
        return file_exists(rtrim($this->projectRoot, '/\\') . '/' . ltrim($relative, '/\\'));
    }
}
