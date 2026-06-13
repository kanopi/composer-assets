<?php

declare(strict_types=1);

namespace Kanopi\Composer\Assets\Tests;

use Kanopi\Composer\Assets\Operations\AppendOp;
use Kanopi\Composer\Assets\Operations\MergeOp;
use Kanopi\Composer\Assets\Operations\OperationFactory;
use Kanopi\Composer\Assets\Operations\ReplaceOp;
use Kanopi\Composer\Assets\Operations\SkipOp;
use PHPUnit\Framework\TestCase;

final class OperationFactoryTest extends TestCase
{
    private function factory(): OperationFactory
    {
        return new OperationFactory('vendor/pkg', '/tmp/pkg');
    }

    public function testStringValueIsReplace(): void
    {
        self::assertInstanceOf(ReplaceOp::class, $this->factory()->create('dest', 'assets/file'));
    }

    public function testFalseValueIsSkip(): void
    {
        self::assertInstanceOf(SkipOp::class, $this->factory()->create('dest', false));
    }

    public function testPathObjectIsReplace(): void
    {
        $op = $this->factory()->create('dest', ['path' => 'assets/file', 'overwrite' => false]);
        self::assertInstanceOf(ReplaceOp::class, $op);
    }

    public function testAppendObjectIsAppend(): void
    {
        $op = $this->factory()->create('dest', ['append' => 'assets/extra']);
        self::assertInstanceOf(AppendOp::class, $op);
    }

    public function testPrependObjectIsAppend(): void
    {
        $op = $this->factory()->create('dest', ['prepend' => 'assets/header']);
        self::assertInstanceOf(AppendOp::class, $op);
    }

    public function testMergeObjectIsMerge(): void
    {
        $op = $this->factory()->create('config.yml', ['merge' => 'assets/overlay.yml', 'array' => 'replace']);
        self::assertInstanceOf(MergeOp::class, $op);
    }

    public function testGitignoreFalseOnReplaceMakesUnmanaged(): void
    {
        $op = $this->factory()->create('.circleci/config.yml', ['path' => 'assets/config.yml', 'gitignore' => false]);
        self::assertInstanceOf(ReplaceOp::class, $op);
        self::assertFalse($op->isManagedFile(), 'gitignore:false must keep the file tracked');
    }

    public function testGitignoreFalseOnMergeMakesUnmanaged(): void
    {
        $op = $this->factory()->create('.circleci/config.yml', [
            'merge' => 'assets/overlay.yml',
            'default' => 'assets/base.yml',
            'gitignore' => false,
        ]);
        self::assertInstanceOf(MergeOp::class, $op);
        self::assertFalse($op->isManagedFile());
    }

    public function testInvalidScalarThrows(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->factory()->create('dest', 42);
    }

    public function testObjectWithoutKnownKeysThrows(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->factory()->create('dest', ['unknown' => 'x']);
    }
}
