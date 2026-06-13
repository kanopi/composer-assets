<?php

declare(strict_types=1);

namespace Kanopi\Composer\Assets\Tests;

use PHPUnit\Framework\TestCase;

/**
 * Base test case that provides an isolated temp working directory.
 */
abstract class TempDirTestCase extends TestCase
{
    protected string $root;
    protected string $package;

    protected function setUp(): void
    {
        parent::setUp();
        $base = sys_get_temp_dir() . '/composer-assets-' . uniqid('', true);
        $this->root = $base . '/project';
        $this->package = $base . '/package';
        mkdir($this->root, 0777, true);
        mkdir($this->package, 0777, true);
    }

    protected function tearDown(): void
    {
        $this->rrmdir(dirname($this->root));
        parent::tearDown();
    }

    protected function writePackageFile(string $relative, string $contents): void
    {
        $full = $this->package . '/' . $relative;
        @mkdir(dirname($full), 0777, true);
        file_put_contents($full, $contents);
    }

    protected function writeProjectFile(string $relative, string $contents): void
    {
        $full = $this->root . '/' . $relative;
        @mkdir(dirname($full), 0777, true);
        file_put_contents($full, $contents);
    }

    protected function projectContents(string $relative): string
    {
        return (string) file_get_contents($this->root . '/' . $relative);
    }

    private function rrmdir(string $dir): void
    {
        if (!is_dir($dir)) {
            return;
        }
        foreach (scandir($dir) ?: [] as $entry) {
            if ($entry === '.' || $entry === '..') {
                continue;
            }
            $path = $dir . '/' . $entry;
            if (is_link($path) || is_file($path)) {
                @unlink($path);
            } else {
                $this->rrmdir($path);
            }
        }
        @rmdir($dir);
    }
}
