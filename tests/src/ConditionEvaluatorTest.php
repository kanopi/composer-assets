<?php

declare(strict_types=1);

namespace Kanopi\Composer\Assets\Tests;

use Kanopi\Composer\Assets\ConditionEvaluator;

final class ConditionEvaluatorTest extends TempDirTestCase
{
    private function evaluator(array $env = []): ConditionEvaluator
    {
        return new ConditionEvaluator(
            ['drupal/core' => '10.2.0', 'acme/recipe' => '1.4.0'],
            '8.3.0',
            $env,
            $this->root,
        );
    }

    // ---- passes() ----

    public function testPackagePresence(): void
    {
        self::assertTrue($this->evaluator()->passes(['package' => 'drupal/core']));
        self::assertFalse($this->evaluator()->passes(['package' => 'nope/missing']));
    }

    public function testPackageVersionConstraint(): void
    {
        self::assertTrue($this->evaluator()->passes(['package' => 'drupal/core:^10']));
        self::assertFalse($this->evaluator()->passes(['package' => 'drupal/core:^11']));
    }

    public function testPhpConstraint(): void
    {
        self::assertTrue($this->evaluator()->passes(['php' => '>=8.1']));
        self::assertFalse($this->evaluator()->passes(['php' => '>=8.4']));
    }

    public function testEnvSetAndEquals(): void
    {
        $e = $this->evaluator(['CI' => 'true', 'EMPTY' => '']);
        self::assertTrue($e->passes(['env' => 'CI']));
        self::assertFalse($e->passes(['env' => 'EMPTY']), 'empty env var is not "set"');
        self::assertFalse($e->passes(['env' => 'ABSENT']));
        self::assertTrue($e->passes(['env' => ['CI' => 'true']]));
        self::assertFalse($e->passes(['env' => ['CI' => 'false']]));
    }

    public function testExists(): void
    {
        $this->writeProjectFile('present.txt', "x\n");
        self::assertTrue($this->evaluator()->passes(['exists' => 'present.txt']));
        self::assertFalse($this->evaluator()->passes(['exists' => 'absent.txt']));
    }

    public function testMultipleKeysAreAnded(): void
    {
        self::assertTrue($this->evaluator()->passes(['package' => 'drupal/core:^10', 'php' => '>=8.1']));
        self::assertFalse($this->evaluator()->passes(['package' => 'drupal/core:^10', 'php' => '>=8.4']));
    }

    public function testUnknownConditionThrows(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->evaluator()->passes(['bogus' => 'x']);
    }

    // ---- resolve() ----

    public function testIfIncludesAndExcludes(): void
    {
        $mapping = [
            'a.txt' => ['path' => 'assets/a', 'if' => ['package' => 'drupal/core']],
            'b.txt' => ['path' => 'assets/b', 'if' => ['package' => 'nope/missing']],
        ];

        self::assertSame(['a.txt' => ['path' => 'assets/a']], $this->evaluator()->resolve($mapping));
    }

    public function testUnlessNegates(): void
    {
        $mapping = ['a.txt' => ['path' => 'assets/a', 'unless' => ['package' => 'drupal/core']]];
        self::assertSame([], $this->evaluator()->resolve($mapping));

        $mapping2 = ['a.txt' => ['path' => 'assets/a', 'unless' => ['package' => 'nope/missing']]];
        self::assertSame(['a.txt' => ['path' => 'assets/a']], $this->evaluator()->resolve($mapping2));
    }

    public function testCandidateListFirstMatchWins(): void
    {
        $mapping = [
            'robots.txt' => [
                ['path' => 'assets/d11', 'if' => ['package' => 'drupal/core:^11']],
                ['path' => 'assets/d10', 'if' => ['package' => 'drupal/core:^10']],
                ['path' => 'assets/fallback'],
            ],
        ];

        self::assertSame(['robots.txt' => ['path' => 'assets/d10']], $this->evaluator()->resolve($mapping));
    }

    public function testCandidateListFallback(): void
    {
        $mapping = [
            'robots.txt' => [
                ['path' => 'assets/d12', 'if' => ['package' => 'drupal/core:^12']],
                ['path' => 'assets/fallback'],
            ],
        ];

        self::assertSame(['robots.txt' => ['path' => 'assets/fallback']], $this->evaluator()->resolve($mapping));
    }

    public function testCandidateListNoMatchOmits(): void
    {
        $mapping = [
            'robots.txt' => [
                ['path' => 'assets/d12', 'if' => ['package' => 'drupal/core:^12']],
            ],
        ];

        self::assertSame([], $this->evaluator()->resolve($mapping));
    }

    public function testPlainValuesPassThrough(): void
    {
        $mapping = [
            'a.txt' => 'assets/a',
            'b.txt' => false,
            'c.json' => ['merge' => 'assets/frag.json', 'force-merge' => true],
        ];

        self::assertSame($mapping, $this->evaluator()->resolve($mapping));
    }

    // ---- mergeConditionalGroups() ----

    public function testPassingGroupMergesOverBase(): void
    {
        $base = ['a.txt' => 'assets/a'];
        $groups = [
            ['if' => ['package' => 'drupal/core:^10'], 'file-mapping' => ['b.txt' => 'assets/b', 'a.txt' => 'assets/a-d10']],
        ];

        self::assertSame(
            ['a.txt' => 'assets/a-d10', 'b.txt' => 'assets/b'],
            $this->evaluator()->mergeConditionalGroups($base, $groups),
        );
    }

    public function testFailingGroupIsSkipped(): void
    {
        $base = ['a.txt' => 'assets/a'];
        $groups = [
            ['if' => ['package' => 'drupal/core:^11'], 'file-mapping' => ['b.txt' => 'assets/b']],
        ];

        self::assertSame($base, $this->evaluator()->mergeConditionalGroups($base, $groups));
    }

    public function testGroupUnlessAndOrdering(): void
    {
        $base = ['a.txt' => 'base'];
        $groups = [
            ['unless' => ['package' => 'nope/missing'], 'file-mapping' => ['a.txt' => 'first']],
            ['file-mapping' => ['a.txt' => 'second']], // no condition -> always applies, wins last
        ];

        self::assertSame(['a.txt' => 'second'], $this->evaluator()->mergeConditionalGroups($base, $groups));
    }

    public function testGroupWithoutFileMappingThrows(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->evaluator()->mergeConditionalGroups([], [['if' => ['package' => 'drupal/core']]]);
    }

    public function testGroupsComposeWithCandidateResolution(): void
    {
        // A group injects a candidate list, which resolve() then collapses.
        $merged = $this->evaluator()->mergeConditionalGroups([], [
            ['if' => ['php' => '>=8.1'], 'file-mapping' => [
                'robots.txt' => [
                    ['path' => 'assets/d11', 'if' => ['package' => 'drupal/core:^11']],
                    ['path' => 'assets/d10', 'if' => ['package' => 'drupal/core:^10']],
                ],
            ]],
        ]);

        self::assertSame(['robots.txt' => ['path' => 'assets/d10']], $this->evaluator()->resolve($merged));
    }
}
