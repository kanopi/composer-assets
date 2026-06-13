<?php

declare(strict_types=1);

namespace Kanopi\Composer\Assets\Tests;

use Composer\IO\NullIO;
use Kanopi\Composer\Assets\AssetFilePath;
use Kanopi\Composer\Assets\Operations\ReplaceOp;

final class ReplaceOpTest extends TempDirTestCase
{
    private function dest(string $relative): AssetFilePath
    {
        return AssetFilePath::destination($this->root, $relative);
    }

    private function src(string $relative): AssetFilePath
    {
        return AssetFilePath::source('vendor/pkg', $this->package, $relative);
    }

    public function testCopiesIntoNestedDestination(): void
    {
        $this->writePackageFile('assets/htaccess', 'DENY ALL');
        $op = new ReplaceOp($this->src('assets/htaccess'));

        $changed = $op->process($this->dest('web/.htaccess'), new NullIO(), false);

        self::assertTrue($changed);
        self::assertSame('DENY ALL', $this->projectContents('web/.htaccess'));
        self::assertTrue($op->isManagedFile());
    }

    public function testOverwriteFalseKeepsExisting(): void
    {
        $this->writePackageFile('assets/robots', 'NEW');
        $this->writeProjectFile('robots.txt', 'EXISTING');
        $op = new ReplaceOp($this->src('assets/robots'), overwrite: false);

        $changed = $op->process($this->dest('robots.txt'), new NullIO(), false);

        self::assertFalse($changed);
        self::assertSame('EXISTING', $this->projectContents('robots.txt'));
    }

    public function testOverwriteTrueReplacesExisting(): void
    {
        $this->writePackageFile('assets/robots', 'NEW');
        $this->writeProjectFile('robots.txt', 'EXISTING');
        $op = new ReplaceOp($this->src('assets/robots'), overwrite: true);

        $op->process($this->dest('robots.txt'), new NullIO(), false);

        self::assertSame('NEW', $this->projectContents('robots.txt'));
    }

    public function testReplacedFileIsManagedByDefault(): void
    {
        self::assertTrue((new ReplaceOp($this->src('x')))->isManagedFile());
    }

    public function testGitignoreFalseKeepsFileTracked(): void
    {
        // e.g. .circleci/config.yml must stay committed for CI to run.
        $op = new ReplaceOp($this->src('x'), gitignore: false);
        self::assertFalse($op->isManagedFile());
    }

    public function testGitignoreTrueForcesManaged(): void
    {
        self::assertTrue((new ReplaceOp($this->src('x'), gitignore: true))->isManagedFile());
    }

    public function testMissingSourceThrows(): void
    {
        $op = new ReplaceOp($this->src('assets/missing'));
        $this->expectException(\RuntimeException::class);
        $op->process($this->dest('x'), new NullIO(), false);
    }

    public function testSymlinkModeCreatesLink(): void
    {
        $this->writePackageFile('assets/index', '<?php // index');
        $op = new ReplaceOp($this->src('assets/index'));

        $op->process($this->dest('web/index.php'), new NullIO(), true);

        self::assertTrue(is_link($this->root . '/web/index.php'));
        self::assertSame('<?php // index', $this->projectContents('web/index.php'));
    }

    public function testPerFileSymlinkOverridesGlobal(): void
    {
        $this->writePackageFile('assets/index', 'data');
        // Global symlink on, but this file forces copy.
        $op = new ReplaceOp($this->src('assets/index'), symlink: false);

        $op->process($this->dest('index.php'), new NullIO(), true);

        self::assertFalse(is_link($this->root . '/index.php'));
        self::assertSame('data', $this->projectContents('index.php'));
    }
}
