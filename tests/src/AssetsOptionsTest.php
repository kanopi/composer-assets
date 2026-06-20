<?php

declare(strict_types=1);

namespace Kanopi\Composer\Assets\Tests;

use Kanopi\Composer\Assets\AssetsOptions;
use PHPUnit\Framework\TestCase;

final class AssetsOptionsTest extends TestCase
{
    public function testDefaults(): void
    {
        $options = AssetsOptions::create([]);

        self::assertFalse($options->symlink());
        self::assertNull($options->gitignore(), 'gitignore defaults to auto (null)');
        self::assertSame([], $options->allowedPackages());
        self::assertFalse($options->hasFileMapping());
    }

    public function testReadsValues(): void
    {
        $options = AssetsOptions::create([
            'symlink' => true,
            'gitignore' => false,
            'allowed-packages' => ['a/b', 'c/d'],
            'file-mapping' => ['web/.htaccess' => 'assets/.htaccess'],
        ]);

        self::assertTrue($options->symlink());
        self::assertFalse($options->gitignore());
        self::assertSame(['a/b', 'c/d'], $options->allowedPackages());
        self::assertTrue($options->hasFileMapping());
        self::assertSame(['web/.htaccess' => 'assets/.htaccess'], $options->fileMapping());
    }

    public function testModeDefaultsToNull(): void
    {
        self::assertNull(AssetsOptions::create([])->mode());
    }

    public function testReadsGlobalMode(): void
    {
        self::assertSame(0755, AssetsOptions::create(['mode' => '0755'])->mode());
    }

    public function testInvalidFileMappingThrows(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        AssetsOptions::create(['file-mapping' => 'nope']);
    }

    public function testInvalidModeThrows(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        AssetsOptions::create(['mode' => 'not-octal']);
    }

    public function testConditionalDefaultsToEmpty(): void
    {
        $options = AssetsOptions::create([]);
        self::assertSame([], $options->conditional());
        self::assertFalse($options->hasConditional());
    }

    public function testReadsConditionalGroups(): void
    {
        $groups = [['if' => ['package' => 'drupal/core'], 'file-mapping' => ['a.txt' => 'assets/a']]];
        $options = AssetsOptions::create(['conditional' => $groups]);
        self::assertTrue($options->hasConditional());
        self::assertSame($groups, $options->conditional());
    }

    public function testInvalidConditionalThrows(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        AssetsOptions::create(['conditional' => 'nope']);
    }
}
