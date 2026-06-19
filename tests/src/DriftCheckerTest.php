<?php

declare(strict_types=1);

namespace Kanopi\Composer\Assets\Tests;

use Composer\IO\BufferIO;
use Composer\IO\NullIO;
use Kanopi\Composer\Assets\AssetFileInfo;
use Kanopi\Composer\Assets\AssetFilePath;
use Kanopi\Composer\Assets\Drift\DriftChecker;
use Kanopi\Composer\Assets\Operations\AppendOp;
use Kanopi\Composer\Assets\Operations\MergeOp;
use Kanopi\Composer\Assets\Operations\ReplaceOp;

final class DriftCheckerTest extends TempDirTestCase
{
    private function dest(string $relative): AssetFilePath
    {
        return AssetFilePath::destination($this->root, $relative);
    }

    private function src(string $relative): AssetFilePath
    {
        return AssetFilePath::source('vendor/pkg', $this->package, $relative);
    }

    /**
     * @param list<AssetFileInfo> $infos
     * @return list<\Kanopi\Composer\Assets\Drift\Drift>
     */
    private function check(array $infos, bool $globalSymlink = false): array
    {
        return (new DriftChecker(new NullIO()))->check($infos, $globalSymlink);
    }

    public function testOverwriteFalseStaleFileDrifts(): void
    {
        $this->writePackageFile('assets/robots', "NEW\n");
        $this->writeProjectFile('robots.txt', "OLD\n");
        $info = new AssetFileInfo($this->dest('robots.txt'), new ReplaceOp($this->src('assets/robots'), overwrite: false));

        $drifts = $this->check([$info]);

        self::assertCount(1, $drifts);
        self::assertSame('robots.txt', $drifts[0]->label());
        self::assertSame("OLD\n", $drifts[0]->current());
        self::assertSame("NEW\n", $drifts[0]->expected());
    }

    public function testOverwriteFalseInSyncDoesNotDrift(): void
    {
        $this->writePackageFile('assets/robots', "SAME\n");
        $this->writeProjectFile('robots.txt', "SAME\n");
        $info = new AssetFileInfo($this->dest('robots.txt'), new ReplaceOp($this->src('assets/robots'), overwrite: false));

        self::assertSame([], $this->check([$info]));
    }

    public function testOverwriteTrueNeverDrifts(): void
    {
        // overwrite:true is re-synced every run, so it is not an owned file.
        $this->writePackageFile('assets/robots', "NEW\n");
        $this->writeProjectFile('robots.txt', "OLD\n");
        $info = new AssetFileInfo($this->dest('robots.txt'), new ReplaceOp($this->src('assets/robots'), overwrite: true));

        self::assertSame([], $this->check([$info]));
    }

    public function testSymlinkNeverDrifts(): void
    {
        $this->writePackageFile('assets/robots', "NEW\n");
        $this->writeProjectFile('robots.txt', "OLD\n");
        $info = new AssetFileInfo($this->dest('robots.txt'), new ReplaceOp($this->src('assets/robots'), overwrite: false, symlink: true));

        self::assertSame([], $this->check([$info]));
    }

    public function testMissingDestinationIsWouldCreateNotDrift(): void
    {
        $this->writePackageFile('assets/robots', "NEW\n");
        $info = new AssetFileInfo($this->dest('robots.txt'), new ReplaceOp($this->src('assets/robots'), overwrite: false));

        self::assertSame([], $this->check([$info]));
    }

    public function testDriftOptOutIsSkipped(): void
    {
        $this->writePackageFile('assets/robots', "NEW\n");
        $this->writeProjectFile('robots.txt', "OLD\n");
        $info = new AssetFileInfo(
            $this->dest('robots.txt'),
            new ReplaceOp($this->src('assets/robots'), overwrite: false),
            driftCheck: false,
        );

        self::assertSame([], $this->check([$info]));
    }

    public function testForceAppendDriftsWhenFragmentMissing(): void
    {
        $this->writePackageFile('assets/snippet', "ADDED\n");
        $this->writeProjectFile('file.txt', "BODY\n");
        $info = new AssetFileInfo($this->dest('file.txt'), new AppendOp(append: $this->src('assets/snippet'), forceAppend: true));

        $drifts = $this->check([$info]);

        self::assertCount(1, $drifts);
        self::assertStringContainsString('ADDED', $drifts[0]->expected());
    }

    public function testForceAppendInSyncDoesNotDrift(): void
    {
        $this->writePackageFile('assets/snippet', "ADDED\n");
        $this->writeProjectFile('file.txt', "BODY\nADDED\n");
        $info = new AssetFileInfo($this->dest('file.txt'), new AppendOp(append: $this->src('assets/snippet'), forceAppend: true));

        self::assertSame([], $this->check([$info]));
    }

    public function testForceMergeDriftsWhenValuesDiffer(): void
    {
        $this->writePackageFile('assets/overlay.json', (string) json_encode(['b' => 2]));
        $this->writeProjectFile('config.json', (string) json_encode(['a' => 1]));
        $info = new AssetFileInfo($this->dest('config.json'), new MergeOp($this->src('assets/overlay.json'), forceMerge: true));

        self::assertCount(1, $this->check([$info]));
    }

    public function testForceMergeInSyncDoesNotDrift(): void
    {
        $merged = (string) json_encode(['a' => 1, 'b' => 2], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) . "\n";
        $this->writeProjectFile('config.json', $merged);
        $this->writePackageFile('assets/overlay.json', (string) json_encode(['b' => 2]));
        $info = new AssetFileInfo($this->dest('config.json'), new MergeOp($this->src('assets/overlay.json'), forceMerge: true));

        self::assertSame([], $this->check([$info]));
    }

    public function testOnlyLimitsReportToRequestedPaths(): void
    {
        $this->writePackageFile('assets/a', "NEW\n");
        $this->writeProjectFile('a.txt', "OLD\n");
        $this->writePackageFile('assets/b', "NEW\n");
        $this->writeProjectFile('b.txt', "OLD\n");
        $infoA = new AssetFileInfo($this->dest('a.txt'), new ReplaceOp($this->src('assets/a'), overwrite: false));
        $infoB = new AssetFileInfo($this->dest('b.txt'), new ReplaceOp($this->src('assets/b'), overwrite: false));

        $drifts = (new DriftChecker(new NullIO()))->check([$infoA, $infoB], false, ['a.txt']);

        self::assertCount(1, $drifts);
        self::assertSame('a.txt', $drifts[0]->label());
    }

    public function testOnlyUnknownPathWarnsAndReportsNothing(): void
    {
        $io = new BufferIO();
        $this->writePackageFile('assets/a', "NEW\n");
        $this->writeProjectFile('a.txt', "OLD\n");
        $info = new AssetFileInfo($this->dest('a.txt'), new ReplaceOp($this->src('assets/a'), overwrite: false));

        $drifts = (new DriftChecker($io))->check([$info], false, ['nope.txt']);

        self::assertSame([], $drifts);
        self::assertStringContainsString('not a managed file', $io->getOutput());
    }

    public function testMergeConcatIsNotChecked(): void
    {
        // concat is not idempotent, so it cannot be reliably drift-checked.
        $this->writePackageFile('assets/overlay.json', (string) json_encode(['list' => [1]]));
        $this->writeProjectFile('config.json', (string) json_encode(['list' => [1]]));
        $info = new AssetFileInfo(
            $this->dest('config.json'),
            new MergeOp($this->src('assets/overlay.json'), arrayStrategy: MergeOp::ARRAY_CONCAT, forceMerge: true),
        );

        self::assertSame([], $this->check([$info]));
    }
}
