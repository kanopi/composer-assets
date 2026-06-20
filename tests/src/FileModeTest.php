<?php

declare(strict_types=1);

namespace Kanopi\Composer\Assets\Tests;

use Kanopi\Composer\Assets\FileMode;
use PHPUnit\Framework\TestCase;

final class FileModeTest extends TestCase
{
    public function testNullIsNull(): void
    {
        self::assertNull(FileMode::parse(null));
    }

    public function testOctalStringForms(): void
    {
        self::assertSame(0755, FileMode::parse('0755'));
        self::assertSame(0755, FileMode::parse('755'));
        self::assertSame(0755, FileMode::parse('0o755'));
        self::assertSame(0644, FileMode::parse('0644'));
    }

    public function testIntegerDigitsReadAsOctal(): void
    {
        self::assertSame(0755, FileMode::parse(755));
    }

    public function testInvalidThrows(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        FileMode::parse('rwxr-xr-x');
    }

    public function testNonOctalDigitThrows(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        FileMode::parse('0899');
    }
}
