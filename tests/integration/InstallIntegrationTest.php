<?php

declare(strict_types=1);

namespace Kanopi\Composer\Assets\Tests\Integration;

use PHPUnit\Framework\TestCase;

/**
 * End-to-end test: builds a throwaway project that requires this plugin plus a
 * fixture "asset provider" via local path repositories, runs a real
 * `composer install`, and asserts the scaffolded files land correctly.
 *
 * This is the regression guard for the plugin's wiring (event subscription,
 * allowed-packages resolution, operation dispatch) — things the unit tests
 * deliberately don't exercise.
 *
 * @group integration
 */
final class InstallIntegrationTest extends TestCase
{
    private string $pluginRoot;
    private string $workdir;
    private string $composerBin;

    protected function setUp(): void
    {
        parent::setUp();

        $this->composerBin = (string) (getenv('COMPOSER_BINARY') ?: 'composer');
        if (!$this->commandExists($this->composerBin)) {
            self::markTestSkipped('composer binary not available on PATH.');
        }

        $this->pluginRoot = dirname(__DIR__, 2);
        $this->workdir = sys_get_temp_dir() . '/composer-assets-it-' . uniqid('', true);
        mkdir($this->workdir . '/web', 0777, true);
    }

    protected function tearDown(): void
    {
        $this->rrmdir($this->workdir);
        parent::tearDown();
    }

    public function testInstallScaffoldsFiles(): void
    {
        $providerPath = $this->pluginRoot . '/tests/fixtures/provider';

        $project = [
            'name' => 'acme/site',
            'type' => 'project',
            'minimum-stability' => 'dev',
            'prefer-stable' => true,
            // Packagist stays enabled so the plugin's symfony/yaml dependency resolves.
            'repositories' => [
                ['type' => 'path', 'url' => $this->pluginRoot, 'options' => ['symlink' => false]],
                ['type' => 'path', 'url' => $providerPath, 'options' => ['symlink' => false]],
            ],
            'require' => [
                'kanopi/composer-assets' => '*',
                'acme/assets-provider' => '*',
            ],
            'extra' => [
                // Global default mode applies to files without their own per-file "mode".
                'composer-assets' => [
                    'allowed-packages' => ['acme/assets-provider'],
                    'mode' => '0664',
                    'file-mapping' => [
                        // Option-only override: inherit the provider's source for this
                        // file and just flip it to owned (overwrite:false), so local
                        // edits are kept and it becomes drift-tracked.
                        'web/override-me.txt' => ['overwrite' => false],
                    ],
                ],
            ],
            'config' => ['allow-plugins' => ['kanopi/composer-assets' => true]],
        ];
        file_put_contents(
            $this->workdir . '/composer.json',
            (string) json_encode($project, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES),
        );

        // Pre-existing files prove overwrite:false protection and in-place force-append.
        file_put_contents($this->workdir . '/web/robots.txt', "KEEP ME\n");
        file_put_contents($this->workdir . '/web/.htaccess-extra', "ORIGINAL\n");
        // Pre-existing, locally-edited copy of the option-only-override target.
        file_put_contents($this->workdir . '/web/override-me.txt', "LOCAL EDIT\n");

        // Pre-existing structured files prove JSON + YAML merge.
        file_put_contents(
            $this->workdir . '/package.json',
            (string) json_encode(['name' => 'acme/site', 'scripts' => ['build' => 'vite build']], JSON_PRETTY_PRINT),
        );
        mkdir($this->workdir . '/.tugboat', 0777, true);
        file_put_contents(
            $this->workdir . '/.tugboat/config.yml',
            "services:\n  php:\n    image: tugboatqa/php:8.1-apache\n  mysql:\n    image: tugboatqa/mysql:8\n",
        );

        [$code, $output] = $this->composer('install --no-interaction');
        self::assertSame(0, $code, "composer install failed:\n" . $output);

        // The owned (overwrite:false) robots.txt stays diverged, so install warns
        // about it (the "still drifted" message).
        self::assertStringContainsString('web/robots.txt has drifted from its package source', $output);

        // Replace: copied from the provider, with the configured permission mode.
        self::assertFileExists($this->workdir . '/web/.htaccess');
        self::assertStringEqualsFile($this->workdir . '/web/.htaccess', "Deny from all\n");
        self::assertSame(0640, fileperms($this->workdir . '/web/.htaccess') & 0777, 'per-file mode "0640" overrides the global default.');

        // Global default mode "0664" applies to a file without its own per-file mode.
        self::assertSame(0664, fileperms($this->workdir . '/.circleci/config.yml') & 0777, 'global default mode must apply when a file sets no mode of its own.');

        // overwrite:false — the pre-existing file is untouched.
        self::assertStringEqualsFile($this->workdir . '/web/robots.txt', "KEEP ME\n");

        // Option-only override: root flipped the provider's overwrite:true mapping
        // to overwrite:false (inheriting its source), so the local edit survives...
        self::assertStringEqualsFile($this->workdir . '/web/override-me.txt', "LOCAL EDIT\n");
        // ...and the file is now drift-tracked (differs from the provider's source).
        [$ovCode, $ovOut] = $this->composer('assets:check --no-interaction');
        self::assertSame(0, $ovCode, $ovOut);
        self::assertStringContainsString('web/override-me.txt', $ovOut);

        // force-append into an existing tracked file.
        self::assertStringEqualsFile($this->workdir . '/web/.htaccess-extra', "ORIGINAL\n# extra rules\n");

        // false — explicitly skipped, never created.
        self::assertFileDoesNotExist($this->workdir . '/web/skip-me.txt');

        // gitignore:false replace — the CI config is scaffolded (and stays tracked).
        self::assertFileExists($this->workdir . '/.circleci/config.yml');

        // Directory mapping — the whole assets/github/ tree is scaffolded, structure preserved.
        self::assertStringEqualsFile($this->workdir . '/.github/CODEOWNERS', "* @acme/team\n");
        self::assertStringEqualsFile($this->workdir . '/.github/workflows/test.yml', "name: test\n");

        // Conditional mappings:
        // - "if" on a present package -> scaffolded.
        self::assertStringEqualsFile($this->workdir . '/web/cond-present.txt', "present\n");
        // - "if" on an absent package -> omitted entirely.
        self::assertFileDoesNotExist($this->workdir . '/web/cond-absent.txt');
        // - candidate list -> the first matching variant (php >= 8.0) wins.
        self::assertStringEqualsFile($this->workdir . '/web/cond-variant.txt', "new\n");

        // Conditional groups: a passing group's file-mapping is scaffolded; a
        // failing group's is not.
        self::assertStringEqualsFile($this->workdir . '/web/group-present.txt', "present\n");
        self::assertFileDoesNotExist($this->workdir . '/web/group-absent.txt');

        // JSON merge: provider's scripts/devDependencies merged into existing package.json.
        $pkg = json_decode((string) file_get_contents($this->workdir . '/package.json'), true);
        self::assertSame('vite build', $pkg['scripts']['build'], 'existing script preserved');
        self::assertSame('eslint .', $pkg['scripts']['lint'], 'provider script merged in');
        self::assertArrayHasKey('eslint', $pkg['devDependencies']);

        // YAML merge: php image overridden, build commands added, mysql untouched.
        $tugboat = \Symfony\Component\Yaml\Yaml::parse((string) file_get_contents($this->workdir . '/.tugboat/config.yml'));
        self::assertSame('tugboatqa/php:8.3-apache', $tugboat['services']['php']['image']);
        self::assertSame(['composer install'], $tugboat['services']['php']['commands']['build']);
        self::assertSame('tugboatqa/mysql:8', $tugboat['services']['mysql']['image']);

        // Hand-edit an overwrite:true file: the next run must warn that it
        // diverged AND reset it to the package source.
        file_put_contents($this->workdir . '/web/.htaccess', "HAND EDITED\n");

        // The `composer assets` command exists and is idempotent.
        [$code2, $output2] = $this->composer('assets --no-interaction');
        self::assertSame(0, $code2, "composer assets failed:\n" . $output2);
        self::assertStringEqualsFile(
            $this->workdir . '/web/.htaccess-extra',
            "ORIGINAL\n# extra rules\n",
            'force-append must not duplicate content on re-run.',
        );

        // The hand-edited overwrite:true file: warned as "updated to match", and reset.
        self::assertStringContainsString(
            'web/.htaccess differed from its package source and was updated to match it',
            $output2,
        );
        self::assertStringEqualsFile($this->workdir . '/web/.htaccess', "Deny from all\n");

        // YAML merge with replace-arrays must be idempotent too.
        $tugboat2 = \Symfony\Component\Yaml\Yaml::parse((string) file_get_contents($this->workdir . '/.tugboat/config.yml'));
        self::assertSame(['composer install'], $tugboat2['services']['php']['commands']['build'], 'merge must not grow list on re-run.');
    }

    public function testDryRunPreviewsAndReapplyResolvesDrift(): void
    {
        $providerPath = $this->pluginRoot . '/tests/fixtures/provider';
        $project = [
            'name' => 'acme/site',
            'type' => 'project',
            'minimum-stability' => 'dev',
            'prefer-stable' => true,
            'repositories' => [
                ['type' => 'path', 'url' => $this->pluginRoot, 'options' => ['symlink' => false]],
                ['type' => 'path', 'url' => $providerPath, 'options' => ['symlink' => false]],
            ],
            'require' => [
                'kanopi/composer-assets' => '*',
                'acme/assets-provider' => '*',
            ],
            'extra' => [
                'composer-assets' => ['allowed-packages' => ['acme/assets-provider']],
            ],
            'config' => ['allow-plugins' => ['kanopi/composer-assets' => true]],
        ];
        file_put_contents(
            $this->workdir . '/composer.json',
            (string) json_encode($project, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES),
        );

        // A pre-existing overwrite:false file that differs from the provider's
        // source: an owned file that drifts.
        file_put_contents($this->workdir . '/web/robots.txt', "KEEP ME\n");
        // Structured files the merge ops need so install doesn't error.
        file_put_contents($this->workdir . '/package.json', '{}');
        mkdir($this->workdir . '/.tugboat', 0777, true);
        file_put_contents($this->workdir . '/.tugboat/config.yml', "services: {}\n");

        [$code, $output] = $this->composer('install --no-interaction');
        self::assertSame(0, $code, "composer install failed:\n" . $output);

        // The owned file kept its local content and so has drifted.
        self::assertStringEqualsFile($this->workdir . '/web/robots.txt', "KEEP ME\n");
        [$checkCode, $checkOut] = $this->composer('assets:check --no-interaction');
        self::assertSame(0, $checkCode, $checkOut);
        self::assertStringContainsString('web/robots.txt', $checkOut);
        self::assertStringContainsString('drifted', $checkOut);

        // --format=json emits machine-readable drift.
        [$jsonCode, $jsonOut] = $this->composer('assets:check --format=json --no-interaction');
        self::assertSame(0, $jsonCode, $jsonOut);
        $decoded = json_decode($jsonOut, true);
        self::assertIsArray($decoded, "assets:check --format=json must emit valid JSON:\n" . $jsonOut);
        self::assertGreaterThanOrEqual(1, $decoded['count']);
        self::assertContains('web/robots.txt', array_column($decoded['drift'], 'path'));

        // assets:status lists the file as drifted.
        [$statusCode, $statusOut] = $this->composer('assets:status --no-interaction');
        self::assertSame(0, $statusCode, $statusOut);
        self::assertStringContainsString('web/robots.txt', $statusOut);
        self::assertStringContainsString('drifted', $statusOut);

        // assets:reapply --dry-run previews without writing.
        [$rdCode, $rdOut] = $this->composer('assets:reapply --dry-run --no-interaction');
        self::assertSame(0, $rdCode, $rdOut);
        self::assertStringContainsString('would be reapplied', $rdOut);
        self::assertStringEqualsFile($this->workdir . '/web/robots.txt', "KEEP ME\n", 'reapply --dry-run must not write.');

        // Dry run of a fresh scaffold: delete a replaced file, preview, assert
        // it is reported but NOT recreated.
        unlink($this->workdir . '/web/.htaccess');
        [$dryCode, $dryOut] = $this->composer('assets --dry-run --no-interaction');
        self::assertSame(0, $dryCode, $dryOut);
        self::assertStringContainsString('dry run', $dryOut);
        self::assertStringContainsString('Would copy', $dryOut);
        self::assertFileDoesNotExist($this->workdir . '/web/.htaccess', 'dry run must not write files.');

        // Reapply resolves the drift by overwriting with the provider source.
        [$reCode, $reOut] = $this->composer('assets:reapply web/robots.txt --yes --no-interaction');
        self::assertSame(0, $reCode, "composer assets:reapply failed:\n" . $reOut);
        self::assertStringContainsString('reapplied 1 of 1', $reOut);
        self::assertStringEqualsFile($this->workdir . '/web/robots.txt', "User-agent: *\nDisallow:\n");

        // Drift is now gone.
        [, $checkOut2] = $this->composer('assets:check --no-interaction');
        self::assertStringContainsString('no drift detected', $checkOut2);
    }

    public function testGitignoreManagementTracksOptedOutFiles(): void
    {
        if (!$this->commandExists('git')) {
            self::markTestSkipped('git binary not available on PATH.');
        }

        $providerPath = $this->pluginRoot . '/tests/fixtures/provider';
        $project = [
            'name' => 'acme/site',
            'type' => 'project',
            'minimum-stability' => 'dev',
            'prefer-stable' => true,
            'repositories' => [
                ['type' => 'path', 'url' => $this->pluginRoot, 'options' => ['symlink' => false]],
                ['type' => 'path', 'url' => $providerPath, 'options' => ['symlink' => false]],
            ],
            'require' => [
                'kanopi/composer-assets' => '*',
                'acme/assets-provider' => '*',
            ],
            'extra' => [
                // Force gitignore management on so the test doesn't depend on auto-detection.
                'composer-assets' => [
                    'allowed-packages' => ['acme/assets-provider'],
                    'gitignore' => true,
                    'file-mapping' => [
                        // Option-only override of a provider file: keep it AND commit it
                        // (overwrite:false + gitignore:false), inheriting the source.
                        'web/override-me.txt' => ['overwrite' => false, 'gitignore' => false],
                    ],
                ],
            ],
            'config' => ['allow-plugins' => ['kanopi/composer-assets' => true]],
        ];
        file_put_contents(
            $this->workdir . '/composer.json',
            (string) json_encode($project, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES),
        );
        // Files merge ops need so the install doesn't error.
        file_put_contents($this->workdir . '/package.json', '{}');
        mkdir($this->workdir . '/.tugboat', 0777, true);
        file_put_contents($this->workdir . '/.tugboat/config.yml', "services: {}\n");

        // Make it a git working copy that ignores vendor/.
        $this->shell('git init -q');
        file_put_contents($this->workdir . '/.gitignore', "/vendor/\n");
        // A stale entry a prior run wrote for the now-overridden (gitignore:false)
        // file: management must retract it. (web/ is created in setUp.)
        file_put_contents($this->workdir . '/web/.gitignore', "/override-me.txt\n");

        [$code, $output] = $this->composer('install --no-interaction');
        self::assertSame(0, $code, "composer install failed:\n" . $output);

        // A normal replaced file is added to its directory's .gitignore.
        self::assertFileExists($this->workdir . '/web/.gitignore');
        self::assertStringContainsString('/.htaccess', $this->projectContents('web/.gitignore'));

        // The gitignore:false CI config is scaffolded but NOT ignored -> git sees it as trackable.
        self::assertFileExists($this->workdir . '/.circleci/config.yml');
        [$ignoredCode] = $this->shell('git check-ignore .circleci/config.yml');
        self::assertSame(1, $ignoredCode, '.circleci/config.yml must NOT be gitignored (CI needs it committed).');

        // Option-only override flipping a provider file to gitignore:false must be
        // honored through the inheritance path AND retract the stale entry a prior
        // run wrote: the file is NOT ignored, and its .gitignore line is gone.
        self::assertFileExists($this->workdir . '/web/override-me.txt');
        [$ovIgnored] = $this->shell('git check-ignore web/override-me.txt');
        self::assertSame(1, $ovIgnored, 'overridden file must NOT be gitignored.');
        self::assertFileExists($this->workdir . '/web/.gitignore');
        self::assertStringNotContainsString('override-me.txt', $this->projectContents('web/.gitignore'), 'stale ignore entry must be retracted.');
        // The directory's other managed file is still ignored, so the file remains.
        self::assertStringContainsString('/.htaccess', $this->projectContents('web/.gitignore'));
    }

    private function projectContents(string $relative): string
    {
        return (string) file_get_contents($this->workdir . '/' . $relative);
    }

    /**
     * Runs `composer <args>` in the temp project.
     *
     * @return array{0:int,1:string}
     */
    private function composer(string $args): array
    {
        return $this->shell(sprintf('COMPOSER_ROOT_VERSION=1.0.0 %s %s', escapeshellarg($this->composerBin), $args));
    }

    /**
     * Runs an arbitrary shell command in the temp project's working directory.
     *
     * @return array{0:int,1:string}
     */
    private function shell(string $command): array
    {
        $output = [];
        $code = 0;
        $cwd = getcwd();
        chdir($this->workdir);
        exec($command . ' 2>&1', $output, $code);
        if ($cwd !== false) {
            chdir($cwd);
        }

        return [$code, implode("\n", $output)];
    }

    private function commandExists(string $bin): bool
    {
        $which = str_contains($bin, '/') ? $bin : trim((string) shell_exec('command -v ' . escapeshellarg($bin) . ' 2>/dev/null'));

        return $which !== '' && is_executable($which);
    }

    private function rrmdir(string $dir): void
    {
        if (!is_dir($dir)) {
            return;
        }
        foreach (scandir($dir) ?: [] as $entry) {
            if ($entry === '.' || $entry === '..') {
                continue;
            }
            $path = $dir . '/' . $entry;
            (is_link($path) || is_file($path)) ? @unlink($path) : $this->rrmdir($path);
        }
        @rmdir($dir);
    }
}
