<?php

declare(strict_types=1);

namespace Kanopi\Composer\Assets\Tests;

use Composer\IO\NullIO;
use Kanopi\Composer\Assets\AssetFilePath;
use Kanopi\Composer\Assets\Operations\MergeOp;
use Symfony\Component\Yaml\Yaml;

final class MergeOpTest extends TempDirTestCase
{
    private function dest(string $relative): AssetFilePath
    {
        return AssetFilePath::destination($this->root, $relative);
    }

    private function src(string $relative): AssetFilePath
    {
        return AssetFilePath::source('vendor/pkg', $this->package, $relative);
    }

    // ---- pure merge semantics ----

    public function testDeepMergeMapsAndScalars(): void
    {
        $base = ['a' => 1, 'nested' => ['x' => 1, 'y' => 2]];
        $overlay = ['b' => 2, 'nested' => ['y' => 9, 'z' => 3]];

        self::assertSame(
            ['a' => 1, 'nested' => ['x' => 1, 'y' => 9, 'z' => 3], 'b' => 2],
            MergeOp::deepMerge($base, $overlay, MergeOp::ARRAY_REPLACE),
        );
    }

    public function testNullDeletesKey(): void
    {
        $merged = MergeOp::deepMerge(['keep' => 1, 'drop' => 2], ['drop' => null], MergeOp::ARRAY_REPLACE);
        self::assertSame(['keep' => 1], $merged);
    }

    public function testArrayReplaceIsDefault(): void
    {
        $merged = MergeOp::deepMerge(['list' => [1, 2, 3]], ['list' => [9]], MergeOp::ARRAY_REPLACE);
        self::assertSame(['list' => [9]], $merged);
    }

    public function testArrayConcat(): void
    {
        $merged = MergeOp::deepMerge(['list' => [1, 2]], ['list' => [3]], MergeOp::ARRAY_CONCAT);
        self::assertSame(['list' => [1, 2, 3]], $merged);
    }

    public function testArrayUniqueDeduplicates(): void
    {
        $merged = MergeOp::deepMerge(['list' => [1, 2]], ['list' => [2, 3]], MergeOp::ARRAY_UNIQUE);
        self::assertSame(['list' => [1, 2, 3]], $merged);
    }

    public function testUnknownArrayStrategyThrows(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        new MergeOp($this->src('x.json'), arrayStrategy: 'bogus');
    }

    // ---- JSON on disk ----

    public function testJsonMergeIntoExisting(): void
    {
        $this->writeProjectFile('package.json', json_encode(['name' => 'site', 'scripts' => ['build' => 'x']]));
        $this->writePackageFile('frag.json', json_encode(['scripts' => ['lint' => 'y'], 'private' => true]));

        $op = new MergeOp($this->src('frag.json'), forceMerge: true);
        $changed = $op->process($this->dest('package.json'), new NullIO(), false);

        self::assertTrue($changed);
        $result = json_decode($this->projectContents('package.json'), true);
        self::assertSame(['build' => 'x', 'lint' => 'y'], $result['scripts']);
        self::assertTrue($result['private']);
        // force-merge onto a tracked file is not a managed (gitignored) file.
        self::assertFalse($op->isManagedFile());
    }

    public function testJsonMergeIsIdempotentWithReplace(): void
    {
        $this->writeProjectFile('a.json', json_encode(['list' => [1, 2]]));
        $this->writePackageFile('frag.json', json_encode(['list' => [9]]));
        $op = new MergeOp($this->src('frag.json'), forceMerge: true);

        self::assertTrue($op->process($this->dest('a.json'), new NullIO(), false));
        self::assertFalse($op->process($this->dest('a.json'), new NullIO(), false), 'second run should be a no-op');
        self::assertSame([9], json_decode($this->projectContents('a.json'), true)['list']);
    }

    public function testSeedsFromDefaultWhenMissing(): void
    {
        $this->writePackageFile('base.json', json_encode(['version' => '2.1', 'jobs' => []]));
        $this->writePackageFile('frag.json', json_encode(['jobs' => ['build' => true]]));

        $op = new MergeOp($this->src('frag.json'), default: $this->src('base.json'), managedDefault: true);
        $changed = $op->process($this->dest('config.json'), new NullIO(), false);

        self::assertTrue($changed);
        $result = json_decode($this->projectContents('config.json'), true);
        self::assertSame('2.1', $result['version']);
        self::assertSame(['build' => true], $result['jobs']);
        // Created from a default, so it is a managed file.
        self::assertTrue($op->isManagedFile());
    }

    public function testSkipsWhenMissingAndNoDefaultOrForce(): void
    {
        $this->writePackageFile('frag.json', json_encode(['a' => 1]));
        $op = new MergeOp($this->src('frag.json'));

        self::assertFalse($op->process($this->dest('missing.json'), new NullIO(), false));
        self::assertFileDoesNotExist($this->root . '/missing.json');
    }

    public function testInvalidJsonThrows(): void
    {
        $this->writeProjectFile('bad.json', '{not json');
        $this->writePackageFile('frag.json', json_encode(['a' => 1]));
        $op = new MergeOp($this->src('frag.json'), forceMerge: true);

        $this->expectException(\RuntimeException::class);
        $op->process($this->dest('bad.json'), new NullIO(), false);
    }

    // ---- YAML on disk (CircleCI / Tugboat shaped) ----

    public function testYamlMergeOverridesServiceAndReplacesCommandList(): void
    {
        $base = <<<YAML
        services:
          php:
            image: php:8.1
            commands:
              build:
                - composer install
          mysql:
            image: mysql:5.7
        YAML;
        $overlay = <<<YAML
        services:
          php:
            image: php:8.3
            commands:
              build:
                - composer install --no-dev
                - npm ci
        YAML;
        $this->writeProjectFile('.tugboat/config.yml', $base);
        $this->writePackageFile('overlay.yml', $overlay);

        $op = new MergeOp($this->src('overlay.yml'), forceMerge: true);
        $changed = $op->process($this->dest('.tugboat/config.yml'), new NullIO(), false);

        self::assertTrue($changed);
        $result = Yaml::parse($this->projectContents('.tugboat/config.yml'));
        self::assertSame('php:8.3', $result['services']['php']['image']);
        // command list replaced (idempotent), not concatenated
        self::assertSame(['composer install --no-dev', 'npm ci'], $result['services']['php']['commands']['build']);
        // untouched service preserved
        self::assertSame('mysql:5.7', $result['services']['mysql']['image']);
    }

    public function testFormatOverrideForUnknownExtension(): void
    {
        $this->writePackageFile('frag', json_encode(['a' => 1]));
        $op = new MergeOp($this->src('frag'), format: 'json', forceMerge: true);

        $op->process($this->dest('weirdname'), new NullIO(), false);
        self::assertSame(['a' => 1], json_decode($this->projectContents('weirdname'), true));
    }

    public function testUninferrableFormatThrows(): void
    {
        $this->writePackageFile('frag', '{}');
        $op = new MergeOp($this->src('frag'), forceMerge: true);

        $this->expectException(\InvalidArgumentException::class);
        $op->process($this->dest('noext'), new NullIO(), false);
    }
}
