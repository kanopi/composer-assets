<?php

declare(strict_types=1);

namespace Kanopi\Composer\Assets\Tests;

use Composer\IO\NullIO;
use Kanopi\Composer\Assets\AssetFileInfo;
use Kanopi\Composer\Assets\AssetFilePath;
use Kanopi\Composer\Assets\Operations\ReplaceOp;

/**
 * Verifies the gitignore-feeding contract: process() returns true only when a
 * file was both written AND is a managed (gitignore-able) file.
 */
final class AssetFileInfoTest extends TempDirTestCase
{
    public function testWrittenManagedFileReportsManaged(): void
    {
        $this->writePackageFile('assets/file', 'data');
        $info = new AssetFileInfo(
            AssetFilePath::destination($this->root, 'web/generated.txt'),
            new ReplaceOp(AssetFilePath::source('vendor/pkg', $this->package, 'assets/file')),
        );

        $managed = $info->process(new NullIO(), false);

        self::assertTrue($managed, 'a normal scaffolded file is managed');
        self::assertFileExists($this->root . '/web/generated.txt');
    }

    public function testGitignoreFalseFileIsWrittenButNotManaged(): void
    {
        $this->writePackageFile('assets/config.yml', "version: 2.1\n");
        $info = new AssetFileInfo(
            AssetFilePath::destination($this->root, '.circleci/config.yml'),
            new ReplaceOp(AssetFilePath::source('vendor/pkg', $this->package, 'assets/config.yml'), gitignore: false),
        );

        $managed = $info->process(new NullIO(), false);

        // File lands on disk...
        self::assertFileExists($this->root . '/.circleci/config.yml');
        self::assertSame("version: 2.1\n", $this->projectContents('.circleci/config.yml'));
        // ...but is NOT fed to gitignore management, so it stays tracked.
        self::assertFalse($managed);
    }
}
