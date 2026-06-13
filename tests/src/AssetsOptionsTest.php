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

    public function testInvalidFileMappingThrows(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        AssetsOptions::create(['file-mapping' => 'nope']);
    }
}
