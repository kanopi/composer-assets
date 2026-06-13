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
                'composer-assets' => ['allowed-packages' => ['acme/assets-provider']],
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

        // Replace: copied from the provider.
        self::assertFileExists($this->workdir . '/web/.htaccess');
        self::assertStringEqualsFile($this->workdir . '/web/.htaccess', "Deny from all\n");

        // overwrite:false — the pre-existing file is untouched.
        self::assertStringEqualsFile($this->workdir . '/web/robots.txt', "KEEP ME\n");

        // force-append into an existing tracked file.
        self::assertStringEqualsFile($this->workdir . '/web/.htaccess-extra', "ORIGINAL\n# extra rules\n");

        // false — explicitly skipped, never created.
        self::assertFileDoesNotExist($this->workdir . '/web/skip-me.txt');

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

        // The `composer assets` command exists and is idempotent.
        [$code2, $output2] = $this->composer('assets --no-interaction');
        self::assertSame(0, $code2, "composer assets failed:\n" . $output2);
        self::assertStringEqualsFile(
            $this->workdir . '/web/.htaccess-extra',
            "ORIGINAL\n# extra rules\n",
            'force-append must not duplicate content on re-run.',
        );

        // YAML merge with replace-arrays must be idempotent too.
        $tugboat2 = \Symfony\Component\Yaml\Yaml::parse((string) file_get_contents($this->workdir . '/.tugboat/config.yml'));
        self::assertSame(['composer install'], $tugboat2['services']['php']['commands']['build'], 'merge must not grow list on re-run.');
    }

    /**
     * @return array{0:int,1:string}
     */
    private function composer(string $args): array
    {
        $cmd = sprintf(
            'COMPOSER_ROOT_VERSION=1.0.0 %s %s 2>&1',
            escapeshellarg($this->composerBin),
            $args,
        );
        $output = [];
        $code = 0;
        $cwd = getcwd();
        chdir($this->workdir);
        exec($cmd, $output, $code);
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
