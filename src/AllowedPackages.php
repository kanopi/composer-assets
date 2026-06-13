<?php

declare(strict_types=1);

namespace Kanopi\Composer\Assets;

use Composer\Composer;
use Composer\IO\IOInterface;
use Composer\Package\PackageInterface;

/**
 * Resolves which packages are permitted to contribute asset files.
 *
 * Rules (agnostic — no CMS package is implicitly trusted):
 *  - The root project is always allowed and is applied LAST so it can override
 *    or skip anything provided by a dependency.
 *  - The root's `allowed-packages` opts dependencies in, in order.
 *  - Delegation: an allowed package may list its own `allowed-packages`, which
 *    are pulled in recursively. A delegated package is ordered before the
 *    package that delegated to it, so the delegator can still override it.
 *  - Later position wins; duplicates collapse to their last occurrence.
 */
final class AllowedPackages
{
    public function __construct(
        private readonly Composer $composer,
        private readonly IOInterface $io,
    ) {
    }

    /**
     * Returns allowed packages in application order (lowest precedence first),
     * with the root package appended last.
     *
     * @return array<string, PackageInterface> keyed by package name
     */
    public function getAllowedPackages(): array
    {
        $root = $this->composer->getPackage();
        $rootExtra = $this->extraFor($root);

        /** @var array<string, PackageInterface> $ordered */
        $ordered = [];
        $visited = [];

        foreach ($rootExtra->allowedPackages() as $name) {
            $this->collect($name, $ordered, $visited);
        }

        // Root always participates and wins ties — apply it last.
        $ordered[$root->getName()] = $root;

        return $ordered;
    }

    /**
     * @param array<string, PackageInterface> $ordered
     * @param array<string, bool>             $visited
     */
    private function collect(string $name, array &$ordered, array &$visited): void
    {
        if (isset($visited[$name])) {
            return;
        }
        $visited[$name] = true;

        $package = $this->findPackage($name);
        if ($package === null) {
            $this->io->writeError(sprintf(
                '<warning>composer-assets: allowed package "%s" is not installed; skipping.</warning>',
                $name,
            ));

            return;
        }

        // Pull in delegated packages first so they have lower precedence.
        foreach ($this->extraFor($package)->allowedPackages() as $delegated) {
            $this->collect($delegated, $ordered, $visited);
        }

        // Re-insert at the end to give later mentions higher precedence.
        unset($ordered[$name]);
        $ordered[$name] = $package;
    }

    private function findPackage(string $name): ?PackageInterface
    {
        $repo = $this->composer->getRepositoryManager()->getLocalRepository();
        foreach ($repo->getPackages() as $package) {
            if (strcasecmp($package->getName(), $name) === 0) {
                return $package;
            }
        }

        return null;
    }

    private function extraFor(PackageInterface $package): AssetsOptions
    {
        $extra = $package->getExtra()['composer-assets'] ?? [];

        return AssetsOptions::create(is_array($extra) ? $extra : []);
    }
}
