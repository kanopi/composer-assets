<?php

declare(strict_types=1);

namespace Kanopi\Composer\Assets\Operations;

use Composer\IO\IOInterface;
use Kanopi\Composer\Assets\AssetFilePath;
use Symfony\Component\Yaml\Yaml;

/**
 * Deep-merges a structured source file (JSON or YAML) into a destination.
 *
 * Unlike {@see AppendOp} (byte-level), this parses both files, merges the data
 * structures, and re-serializes — so the result is always syntactically valid.
 * Built for config boilerplate: composer.json/package.json fragments, CircleCI
 * `.circleci/config.yml`, Tugboat `.tugboat/config.yml`, eslint/tsconfig, etc.
 *
 * Merge semantics (RFC 7386-flavored):
 *  - Maps merge key-by-key; the source wins on scalar conflicts.
 *  - A source value of `null` DELETES that key from the destination.
 *  - Arrays follow the chosen strategy (default `replace`, which is idempotent):
 *      replace — source array overwrites the destination array.
 *      concat  — destination then source (NOT idempotent; re-runs grow it).
 *      unique  — concat then de-duplicate (idempotent for scalars).
 *
 * Caveats: re-serialization discards comments, and YAML anchors/aliases are
 * expanded inline. Use on managed/generated files, not hand-curated ones.
 */
final class MergeOp implements OperationInterface
{
    public const ARRAY_REPLACE = 'replace';
    public const ARRAY_CONCAT = 'concat';
    public const ARRAY_UNIQUE = 'unique';

    public function __construct(
        private readonly AssetFilePath $source,
        private readonly ?AssetFilePath $default = null,
        private readonly ?string $format = null,
        private readonly string $arrayStrategy = self::ARRAY_REPLACE,
        private readonly bool $forceMerge = false,
        private readonly bool $managedDefault = false,
        private readonly ?bool $gitignore = null,
    ) {
        if (!in_array($arrayStrategy, [self::ARRAY_REPLACE, self::ARRAY_CONCAT, self::ARRAY_UNIQUE], true)) {
            throw new \InvalidArgumentException(sprintf('Unknown merge array strategy "%s".', $arrayStrategy));
        }
    }

    public function process(AssetFilePath $destination, IOInterface $io, bool $globalSymlink): bool
    {
        $destPath = $destination->fullPath();
        $label = $destination->relativePath();
        $format = $this->resolveFormat($label);

        if (!$this->source->exists()) {
            throw new \RuntimeException(sprintf(
                'Could not find merge source "%s" in package "%s".',
                $this->source->relativePath(),
                $this->source->packageName() ?: '(root)',
            ));
        }

        $exists = file_exists($destPath);
        if (!$exists && !$this->forceMerge && $this->default === null) {
            $io->write(
                sprintf('  - Skip merge <info>%s</info>: target does not exist and no default provided', $label),
                true,
                IOInterface::VERBOSE,
            );

            return false;
        }

        // Base = existing destination, else the default, else empty.
        $base = [];
        if ($exists) {
            $base = $this->decode((string) file_get_contents($destPath), $format, $destPath);
        } elseif ($this->default !== null) {
            if (!$this->default->exists()) {
                throw new \RuntimeException(sprintf('Merge default "%s" not found.', $this->default->relativePath()));
            }
            $base = $this->decode((string) file_get_contents($this->default->fullPath()), $format, $this->default->fullPath());
        }

        $overlay = $this->decode((string) file_get_contents($this->source->fullPath()), $format, $this->source->fullPath());

        $merged = self::deepMerge($base, $overlay, $this->arrayStrategy);
        $encoded = $this->encode($merged, $format);

        // Idempotency / change detection: skip the write when nothing differs.
        if ($exists && $encoded === (string) file_get_contents($destPath)) {
            $io->write(sprintf('  - Skip merge <info>%s</info>: no changes', $label), true, IOInterface::VERBOSE);

            return false;
        }

        $this->ensureDirectory(dirname($destPath));
        file_put_contents($destPath, $encoded);
        $io->write(sprintf('  - Merge <info>%s</info> (%s) from <comment>%s</comment>', $label, $format, $this->source->packageName() ?: 'root'));

        return true;
    }

    public function isManagedFile(): bool
    {
        if ($this->gitignore !== null) {
            return $this->gitignore; // explicit per-file override wins.
        }

        // Only ours to gitignore when we created it from a default; merging into
        // a pre-existing (force-merge) file leaves a tracked project file.
        return $this->managedDefault && !$this->forceMerge;
    }

    /**
     * Recursively merges $overlay onto $base.
     *
     * @param mixed $base
     * @param mixed $overlay
     */
    public static function deepMerge(mixed $base, mixed $overlay, string $arrayStrategy): mixed
    {
        // Two lists -> apply the array strategy.
        if (is_array($base) && is_array($overlay) && self::isList($base) && self::isList($overlay)) {
            return self::mergeArrays($base, $overlay, $arrayStrategy);
        }

        // Two maps -> merge key by key.
        if (self::isMap($base) && self::isMap($overlay)) {
            $result = $base;
            foreach ($overlay as $key => $value) {
                if ($value === null) {
                    unset($result[$key]); // RFC 7386 delete.
                    continue;
                }
                $result[$key] = array_key_exists($key, $result)
                    ? self::deepMerge($result[$key], $value, $arrayStrategy)
                    : $value;
            }

            return $result;
        }

        // Type mismatch or scalars -> overlay wins.
        return $overlay;
    }

    /**
     * @param array<int, mixed> $base
     * @param array<int, mixed> $overlay
     * @return array<int, mixed>
     */
    private static function mergeArrays(array $base, array $overlay, string $strategy): array
    {
        return match ($strategy) {
            self::ARRAY_CONCAT => array_merge($base, $overlay),
            self::ARRAY_UNIQUE => array_values(self::uniqueValues(array_merge($base, $overlay))),
            default => $overlay, // replace
        };
    }

    /**
     * De-duplicates a list, preserving order, comparing scalars by value and
     * structured items by their serialized form.
     *
     * @param array<int, mixed> $items
     * @return array<int, mixed>
     */
    private static function uniqueValues(array $items): array
    {
        $seen = [];
        $out = [];
        foreach ($items as $item) {
            $key = is_scalar($item) || $item === null ? gettype($item) . ':' . var_export($item, true) : 'json:' . json_encode($item);
            if (!isset($seen[$key])) {
                $seen[$key] = true;
                $out[] = $item;
            }
        }

        return $out;
    }

    private static function isList(mixed $value): bool
    {
        return is_array($value) && array_is_list($value);
    }

    private static function isMap(mixed $value): bool
    {
        return is_array($value) && !array_is_list($value);
    }

    private function resolveFormat(string $label): string
    {
        if ($this->format !== null) {
            $format = strtolower($this->format);
            if ($format === 'yml') {
                $format = 'yaml';
            }

            return $format;
        }

        $ext = strtolower(pathinfo($label, PATHINFO_EXTENSION));

        return match ($ext) {
            'json' => 'json',
            'yml', 'yaml' => 'yaml',
            default => throw new \InvalidArgumentException(sprintf(
                'Cannot infer merge format for "%s"; set "format": "json" or "yaml".',
                $label,
            )),
        };
    }

    /**
     * @return array<int|string, mixed>
     */
    private function decode(string $contents, string $format, string $path): array
    {
        if (trim($contents) === '') {
            return [];
        }

        if ($format === 'json') {
            try {
                $data = json_decode($contents, true, 512, JSON_THROW_ON_ERROR);
            } catch (\JsonException $e) {
                throw new \RuntimeException(sprintf('Invalid JSON in "%s": %s', $path, $e->getMessage()), 0, $e);
            }
        } else {
            $this->requireYaml($path);
            try {
                $data = Yaml::parse($contents);
            } catch (\Throwable $e) {
                throw new \RuntimeException(sprintf('Invalid YAML in "%s": %s', $path, $e->getMessage()), 0, $e);
            }
        }

        return is_array($data) ? $data : [$data];
    }

    /**
     * @param array<int|string, mixed> $data
     */
    private function encode(array $data, string $format): string
    {
        if ($format === 'json') {
            return json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) . "\n";
        }

        $this->requireYaml('(output)');

        return Yaml::dump($data, 8, 2, Yaml::DUMP_MULTI_LINE_LITERAL_BLOCK);
    }

    private function requireYaml(string $path): void
    {
        if (!class_exists(Yaml::class)) {
            throw new \RuntimeException(sprintf(
                'Merging YAML ("%s") requires symfony/yaml. Run: composer require symfony/yaml',
                $path,
            ));
        }
    }

    private function ensureDirectory(string $dir): void
    {
        if (!is_dir($dir) && !@mkdir($dir, 0777, true) && !is_dir($dir)) {
            throw new \RuntimeException(sprintf('Could not create directory "%s".', $dir));
        }
    }
}
