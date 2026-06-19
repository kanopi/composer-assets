<?php

declare(strict_types=1);

namespace Kanopi\Composer\Assets\Drift;

use Symfony\Component\Console\Formatter\OutputFormatter;

/**
 * A minimal, self-contained unified-diff renderer (LCS-based).
 *
 * Drift output ships in normal runs, so it must not depend on a dev-only diff
 * library (e.g. sebastian/diff). Output mirrors `diff -u`: `@@` hunk headers
 * with a few lines of context, `-` for the current file, `+` for the expected.
 */
final class UnifiedDiff
{
    private const CONTEXT = 3;

    public static function render(string $from, string $to): string
    {
        if ($from === $to) {
            return '';
        }

        $a = explode("\n", $from);
        $b = explode("\n", $to);

        return self::hunks(self::diff($a, $b));
    }

    /**
     * Wraps a rendered diff in Symfony Console style tags: green additions, red
     * deletions, cyan hunk headers. Line content is escaped so file text that
     * looks like a tag (e.g. HTML) is not interpreted. When the output is not
     * decorated (piped, --no-ansi, CI), the formatter strips the tags, leaving
     * plain text.
     */
    public static function colorize(string $diff): string
    {
        if ($diff === '') {
            return '';
        }

        $lines = explode("\n", $diff);
        foreach ($lines as $i => $line) {
            $escaped = OutputFormatter::escape($line);
            $lines[$i] = match (true) {
                str_starts_with($line, '@@') => '<fg=cyan>' . $escaped . '</>',
                str_starts_with($line, '+') => '<fg=green>' . $escaped . '</>',
                str_starts_with($line, '-') => '<fg=red>' . $escaped . '</>',
                default => $escaped,
            };
        }

        return implode("\n", $lines);
    }

    /**
     * Produces a line-by-line edit script: each entry is [sign, text] with sign
     * one of ' ' (common), '-' (only in $a), '+' (only in $b).
     *
     * @param list<string> $a
     * @param list<string> $b
     * @return list<array{0: string, 1: string}>
     */
    private static function diff(array $a, array $b): array
    {
        $n = count($a);
        $m = count($b);

        // lcs[$i][$j] = length of the longest common subsequence of a[i:] and b[j:].
        $lcs = [];
        for ($i = 0; $i <= $n; $i++) {
            $lcs[$i] = array_fill(0, $m + 1, 0);
        }
        for ($i = $n - 1; $i >= 0; $i--) {
            for ($j = $m - 1; $j >= 0; $j--) {
                $lcs[$i][$j] = $a[$i] === $b[$j]
                    ? $lcs[$i + 1][$j + 1] + 1
                    : max($lcs[$i + 1][$j], $lcs[$i][$j + 1]);
            }
        }

        $out = [];
        $i = 0;
        $j = 0;
        while ($i < $n && $j < $m) {
            if ($a[$i] === $b[$j]) {
                $out[] = [' ', $a[$i]];
                $i++;
                $j++;
            } elseif ($lcs[$i + 1][$j] >= $lcs[$i][$j + 1]) {
                $out[] = ['-', $a[$i]];
                $i++;
            } else {
                $out[] = ['+', $b[$j]];
                $j++;
            }
        }
        while ($i < $n) {
            $out[] = ['-', $a[$i]];
            $i++;
        }
        while ($j < $m) {
            $out[] = ['+', $b[$j]];
            $j++;
        }

        return $out;
    }

    /**
     * Groups the edit script into unified-diff hunks with surrounding context.
     *
     * @param list<array{0: string, 1: string}> $diff
     */
    private static function hunks(array $diff): string
    {
        $count = count($diff);

        // Mark changed lines and precompute 1-based line numbers per side.
        $changed = [];
        $aNums = [];
        $bNums = [];
        $aLine = 1;
        $bLine = 1;
        foreach ($diff as $k => [$sign]) {
            $aNums[$k] = $aLine;
            $bNums[$k] = $bLine;
            if ($sign === ' ') {
                $aLine++;
                $bLine++;
            } elseif ($sign === '-') {
                $changed[$k] = true;
                $aLine++;
            } else {
                $changed[$k] = true;
                $bLine++;
            }
        }

        if ($changed === []) {
            return '';
        }

        $hunks = [];
        $i = 0;
        while ($i < $count) {
            if (!isset($changed[$i])) {
                $i++;
                continue;
            }

            $start = max(0, $i - self::CONTEXT);
            $end = $i;
            $j = $i;
            // Extend the hunk while the next change is close enough to merge.
            while ($j < $count) {
                if (isset($changed[$j])) {
                    $end = $j;
                    $j++;
                    continue;
                }
                $next = $j;
                while ($next < $count && !isset($changed[$next])) {
                    $next++;
                }
                if ($next < $count && ($next - $end) <= 2 * self::CONTEXT) {
                    $j = $next;
                    continue;
                }
                break;
            }
            $hunkEnd = min($count - 1, $end + self::CONTEXT);

            $body = [];
            $aStart = $aNums[$start];
            $bStart = $bNums[$start];
            $aCnt = 0;
            $bCnt = 0;
            for ($k = $start; $k <= $hunkEnd; $k++) {
                [$sign, $text] = $diff[$k];
                if ($sign === ' ') {
                    $aCnt++;
                    $bCnt++;
                } elseif ($sign === '-') {
                    $aCnt++;
                } else {
                    $bCnt++;
                }
                $body[] = $sign . $text;
            }

            $hunks[] = sprintf('@@ -%d,%d +%d,%d @@', $aStart, $aCnt, $bStart, $bCnt)
                . "\n" . implode("\n", $body);

            $i = $hunkEnd + 1;
        }

        return implode("\n", $hunks);
    }
}
