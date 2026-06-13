<?php

declare(strict_types=1);

namespace Kanopi\Composer\Assets\Tests;

use Composer\IO\NullIO;
use Kanopi\Composer\Assets\AssetFilePath;
use Kanopi\Composer\Assets\Operations\AppendOp;

final class AppendOpTest extends TempDirTestCase
{
    private function dest(string $relative): AssetFilePath
    {
        return AssetFilePath::destination($this->root, $relative);
    }

    private function src(string $relative): AssetFilePath
    {
        return AssetFilePath::source('vendor/pkg', $this->package, $relative);
    }

    public function testAppendsAndPrependsAroundDefault(): void
    {
        $this->writePackageFile('default', "BODY\n");
        $this->writePackageFile('head', "HEADER\n");
        $this->writePackageFile('tail', "FOOTER\n");

        $op = new AppendOp(
            prepend: $this->src('head'),
            append: $this->src('tail'),
            default: $this->src('default'),
            managedDefault: true,
        );

        $changed = $op->process($this->dest('settings.php'), new NullIO(), false);

        self::assertTrue($changed);
        self::assertSame("HEADER\nBODY\nFOOTER\n", $this->projectContents('settings.php'));
        // Default was laid down by us, so it is a managed file.
        self::assertTrue($op->isManagedFile());
    }

    public function testSkipsWhenNoTargetAndNoDefault(): void
    {
        $this->writePackageFile('tail', 'X');
        $op = new AppendOp(append: $this->src('tail'));

        $changed = $op->process($this->dest('missing.txt'), new NullIO(), false);

        self::assertFalse($changed);
        self::assertFileDoesNotExist($this->root . '/missing.txt');
    }

    public function testForceAppendModifiesPreexistingFile(): void
    {
        $this->writeProjectFile('.htaccess', "ORIGINAL\n");
        $this->writePackageFile('tail', "EXTRA\n");
        $op = new AppendOp(append: $this->src('tail'), forceAppend: true);

        $changed = $op->process($this->dest('.htaccess'), new NullIO(), false);

        self::assertTrue($changed);
        self::assertSame("ORIGINAL\nEXTRA\n", $this->projectContents('.htaccess'));
        // Force-appended pre-existing file is NOT ours to gitignore.
        self::assertFalse($op->isManagedFile());
    }

    public function testIsIdempotent(): void
    {
        $this->writeProjectFile('.htaccess', "ORIGINAL\n");
        $this->writePackageFile('tail', "EXTRA\n");
        $op = new AppendOp(append: $this->src('tail'), forceAppend: true);

        $op->process($this->dest('.htaccess'), new NullIO(), false);
        $second = $op->process($this->dest('.htaccess'), new NullIO(), false);

        self::assertFalse($second, 'Second run should detect content already present.');
        self::assertSame("ORIGINAL\nEXTRA\n", $this->projectContents('.htaccess'));
    }
}
