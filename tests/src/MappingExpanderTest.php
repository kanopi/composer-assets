<?php

declare(strict_types=1);

namespace Kanopi\Composer\Assets\Tests;

use Composer\IO\BufferIO;
use Composer\IO\NullIO;
use Kanopi\Composer\Assets\MappingExpander;

final class MappingExpanderTest extends TempDirTestCase
{
    /**
     * @param array<string, mixed> $mapping
     * @return array<string, mixed>
     */
    private function expand(array $mapping): array
    {
        return (new MappingExpander(new NullIO()))->expand($mapping, $this->package);
    }

    public function testDirectoryExpandsRecursivelyPreservingStructure(): void
    {
        $this->writePackageFile('assets/gh/CODEOWNERS', "* @team\n");
        $this->writePackageFile('assets/gh/workflows/ci.yml', "name: ci\n");

        $expanded = $this->expand(['.github/' => 'assets/gh/']);

        self::assertSame([
            '.github/CODEOWNERS' => 'assets/gh/CODEOWNERS',
            '.github/workflows/ci.yml' => 'assets/gh/workflows/ci.yml',
        ], $expanded);
    }

    public function testDirectoryWithoutTrailingSlashAlsoExpands(): void
    {
        $this->writePackageFile('assets/gh/CODEOWNERS', "* @team\n");

        $expanded = $this->expand(['.github' => 'assets/gh']);

        self::assertSame(['.github/CODEOWNERS' => 'assets/gh/CODEOWNERS'], $expanded);
    }

    public function testGlobExpandsByBasenameAndFiltersNonMatches(): void
    {
        $this->writePackageFile('assets/ci/a.yml', "a\n");
        $this->writePackageFile('assets/ci/b.yml', "b\n");
        $this->writePackageFile('assets/ci/readme.md', "nope\n");

        $expanded = $this->expand(['.circleci/' => 'assets/ci/*.yml']);

        self::assertSame([
            '.circleci/a.yml' => 'assets/ci/a.yml',
            '.circleci/b.yml' => 'assets/ci/b.yml',
        ], $expanded);
    }

    public function testOptionsPropagateToEveryExpandedEntry(): void
    {
        $this->writePackageFile('assets/bin/one.sh', "#!/bin/sh\n");
        $this->writePackageFile('assets/bin/two.sh', "#!/bin/sh\n");

        $expanded = $this->expand([
            'bin/' => ['path' => 'assets/bin/', 'mode' => '0755', 'overwrite' => false],
        ]);

        self::assertSame([
            'bin/one.sh' => ['path' => 'assets/bin/one.sh', 'mode' => '0755', 'overwrite' => false],
            'bin/two.sh' => ['path' => 'assets/bin/two.sh', 'mode' => '0755', 'overwrite' => false],
        ], $expanded);
    }

    public function testRootDestinationPlacesFilesAtProjectRoot(): void
    {
        $this->writePackageFile('assets/root/.editorconfig', "root = true\n");

        $expanded = $this->expand(['' => 'assets/root/']);

        self::assertSame(['.editorconfig' => 'assets/root/.editorconfig'], $expanded);
    }

    public function testNonReplaceEntriesPassThroughUntouched(): void
    {
        $this->writePackageFile('assets/single.txt', "x\n");
        $mapping = [
            'a.txt' => 'assets/single.txt',                 // plain file -> unchanged
            'b.txt' => false,                                // skip
            'c.json' => ['merge' => 'assets/frag.json'],     // merge
            'd.txt' => ['append' => 'assets/tail.txt'],      // append
        ];

        self::assertSame($mapping, $this->expand($mapping));
    }

    public function testGlobWithNoMatchesWarnsAndYieldsNothing(): void
    {
        $io = new BufferIO();
        $expanded = (new MappingExpander($io))->expand(['x/' => 'assets/none/*.yml'], $this->package);

        self::assertSame([], $expanded);
        self::assertStringContainsString('matched no files', $io->getOutput());
    }
}
