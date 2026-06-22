<?php

declare(strict_types=1);

namespace Kanopi\Composer\Assets\Tests;

use Kanopi\Composer\Assets\Tokens;
use PHPUnit\Framework\TestCase;

final class TokensTest extends TestCase
{
    private function tokens(string $webRoot = 'web'): Tokens
    {
        return new Tokens(['[project-root]' => '', '[web-root]' => $webRoot]);
    }

    public function testWebRootToken(): void
    {
        self::assertSame('web/robots.txt', $this->tokens()->expand('[web-root]/robots.txt'));
    }

    public function testProjectRootTokenIsRootRelative(): void
    {
        self::assertSame('robots.txt', $this->tokens()->expand('[project-root]/robots.txt'));
    }

    public function testWebRootAtProjectRootCollapses(): void
    {
        // When web-root resolves to the project root (empty), the prefix vanishes.
        self::assertSame('robots.txt', $this->tokens('')->expand('[web-root]/robots.txt'));
    }

    public function testNestedWebRoot(): void
    {
        self::assertSame('public/wp/.htaccess', $this->tokens('public')->expand('[web-root]/wp/.htaccess'));
    }

    public function testUntokenizedPathUnchanged(): void
    {
        self::assertSame('web/.htaccess', $this->tokens()->expand('web/.htaccess'));
    }

    public function testUnknownTokenThrows(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->tokens()->expand('[webroot]/x'); // misspelled
    }

    public function testExpandKeysRewritesKeysOnly(): void
    {
        $mapping = ['[web-root]/robots.txt' => ['path' => 'assets/robots.txt', 'overwrite' => false]];
        self::assertSame(
            ['web/robots.txt' => ['path' => 'assets/robots.txt', 'overwrite' => false]],
            $this->tokens()->expandKeys($mapping),
        );
    }
}
