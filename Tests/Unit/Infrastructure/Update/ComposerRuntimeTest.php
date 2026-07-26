<?php

declare(strict_types=1);

/*
 * This file is part of the "Guardian for TYPO3" extension for TYPO3 CMS.
 *
 * @author    V&T Innovations Team
 * @license   LGPL-3.0-or-later
 * @copyright V&T Innovations 2026 - 2028
 */

namespace Vtinnovations\GuardianTypo3\Tests\Unit\Infrastructure\Update;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Vtinnovations\GuardianTypo3\Domain\Exception\GuardianException;
use Vtinnovations\GuardianTypo3\Infrastructure\Update\ComposerRuntime;
use Vtinnovations\GuardianTypo3\Tests\Unit\Infrastructure\Recovery\FakeWorkingDirectory;

final class ComposerRuntimeTest extends TestCase
{
    private string $base;
    private ComposerRuntime $runtime;

    protected function setUp(): void
    {
        $this->base = sys_get_temp_dir() . '/guardian-composer-rt-' . bin2hex(random_bytes(6));
        $this->runtime = new ComposerRuntime(new FakeWorkingDirectory($this->base));
    }

    protected function tearDown(): void
    {
        @exec('rm -rf ' . escapeshellarg($this->base));
    }

    #[Test]
    public function ensureCreatesThePrivateRuntimeDirectories(): void
    {
        $env = $this->runtime->ensure();

        self::assertDirectoryExists($this->base . '/runtime/home');
        self::assertDirectoryExists($this->base . '/runtime/composer-home');
        self::assertDirectoryExists($this->base . '/runtime/composer-cache');
        self::assertDirectoryIsWritable($this->base . '/runtime/composer-home');
        // The returned overlay carries exactly the required keys.
        self::assertSame(['HOME', 'COMPOSER_HOME', 'COMPOSER_CACHE_DIR', 'COMPOSER_NO_INTERACTION'], array_keys($env));
    }

    #[Test]
    public function envPointsHomeAndComposerHomeAtTheRuntimeDirectories(): void
    {
        $env = $this->runtime->env();
        self::assertSame($this->base . '/runtime/home', $env['HOME']);
        self::assertSame($this->base . '/runtime/composer-home', $env['COMPOSER_HOME']);
        self::assertSame($this->base . '/runtime/composer-cache', $env['COMPOSER_CACHE_DIR']);
        self::assertSame('1', $env['COMPOSER_NO_INTERACTION']);
    }

    #[Test]
    public function unwritableRuntimeFailsWithThePreciseCode(): void
    {
        // Make "runtime" a FILE so the child directories cannot be created.
        mkdir($this->base, 0o755, true);
        file_put_contents($this->base . '/runtime', 'x');

        $this->expectException(GuardianException::class);
        $this->expectExceptionMessage('composer_runtime_directory_unavailable');
        $this->runtime->ensure();
    }

    #[Test]
    public function preflightReportsPreciseCodesForMissingPrerequisites(): void
    {
        $php = $this->base . '/php';
        $composer = $this->base . '/composer.phar';
        $project = $this->base . '/project';
        mkdir($project, 0o755, true);

        $expect = function (string $code, callable $fn): void {
            try {
                $fn();
                self::fail('expected ' . $code);
            } catch (GuardianException $e) {
                self::assertSame($code, $e->getMessage());
            }
        };

        $expect('composer_php_binary_missing', fn () => $this->runtime->preflight('/does/not/exist', $composer, $project));

        file_put_contents($php, "#!/bin/sh\n");
        chmod($php, 0o755);
        $expect('composer_phar_unreadable', fn () => $this->runtime->preflight($php, '/no/composer.phar', $project));

        file_put_contents($composer, '<?php');
        $expect('composer_manifest_missing', fn () => $this->runtime->preflight($php, $composer, $project));

        // With everything present, preflight passes.
        file_put_contents($project . '/composer.json', '{}');
        $this->runtime->preflight($php, $composer, $project);
        $this->addToAssertionCount(1);
    }

    #[Test]
    public function redactStripsCredentialsFromDiagnostics(): void
    {
        $stderr = 'Downloading with token=SECRET-abc123 and --password=hunter2 via https://user:pw@example.com/x';
        $redacted = $this->runtime->redact($stderr);

        self::assertStringNotContainsString('SECRET-abc123', $redacted);
        self::assertStringNotContainsString('hunter2', $redacted);
        self::assertStringNotContainsString('user:pw@', $redacted);
        self::assertStringContainsString('***', $redacted);
    }

    #[Test]
    public function developmentBuildWarningIsAStandaloneNonBlockingRecommendation(): void
    {
        $rec = $this->runtime->developmentBuildRecommendation('Warning: This is a development build of Composer.');
        self::assertNotNull($rec);
        self::assertStringContainsString('recommended', $rec);

        // The HOME failure is a DIFFERENT thing and is not treated as this warning.
        self::assertNull($this->runtime->developmentBuildRecommendation('The HOME or COMPOSER_HOME environment variable must be set'));
    }

    #[Test]
    public function diagnosticsExposeOnlyProtectedFields(): void
    {
        $this->runtime->ensure();
        $diag = $this->runtime->diagnostics(0, 'development build of Composer; token=SECRET');

        self::assertTrue($diag['home_configured']);
        self::assertTrue($diag['composer_home_configured']);
        self::assertTrue($diag['runtime_writable']);
        self::assertSame(0, $diag['exit_code']); // exit 0 stays non-blocking
        self::assertStringNotContainsString('SECRET', $diag['stderr_summary']);
        self::assertNotNull($diag['composer_recommendation']);
    }
}
