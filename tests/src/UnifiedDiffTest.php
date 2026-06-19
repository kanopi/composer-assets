<?php

declare(strict_types=1);

namespace Kanopi\Composer\Assets\Tests;

use Kanopi\Composer\Assets\Drift\UnifiedDiff;
use PHPUnit\Framework\TestCase;

final class UnifiedDiffTest extends TestCase
{
    public function testIdenticalContentYieldsEmptyDiff(): void
    {
        self::assertSame('', UnifiedDiff::render("a\nb\nc\n", "a\nb\nc\n"));
    }

    public function testRendersChangedLineWithMarkers(): void
    {
        $diff = UnifiedDiff::render("a\nb\nc\n", "a\nB\nc\n");

        self::assertStringContainsString('@@', $diff);
        self::assertStringContainsString('-b', $diff);
        self::assertStringContainsString('+B', $diff);
        self::assertStringContainsString(' a', $diff); // context line retained
    }

    public function testRendersPureAddition(): void
    {
        $diff = UnifiedDiff::render("a\n", "a\nb\n");

        self::assertStringContainsString('+b', $diff);
        // No deletion line (a leading '-' on its own line); the hunk header has one.
        self::assertDoesNotMatchRegularExpression('/^-/m', $diff);
    }

    public function testRendersPureDeletion(): void
    {
        $diff = UnifiedDiff::render("a\nb\n", "a\n");

        self::assertStringContainsString('-b', $diff);
    }

    public function testColorizeWrapsLinesInStyleTags(): void
    {
        $colored = UnifiedDiff::colorize(UnifiedDiff::render("a\nb\nc\n", "a\nB\nc\n"));

        self::assertStringContainsString('<fg=cyan>@@', $colored);
        self::assertStringContainsString('<fg=red>-b</>', $colored);
        self::assertStringContainsString('<fg=green>+B</>', $colored);
    }

    public function testColorizeEscapesTagLikeContent(): void
    {
        // A deletion line whose content looks like a Console tag must be escaped
        // so the formatter does not interpret it.
        $colored = UnifiedDiff::colorize(UnifiedDiff::render("<info>\n", "x\n"));

        self::assertStringContainsString('\\<info', $colored);
    }

    public function testColorizeEmptyStaysEmpty(): void
    {
        self::assertSame('', UnifiedDiff::colorize(''));
    }
}
