<?php

declare(strict_types=1);

/*
 * This file is part of the "Guardian for TYPO3" extension for TYPO3 CMS.
 *
 * @author    V&T Innovations Team
 * @license   LGPL-3.0-or-later
 * @copyright V&T Innovations 2026 - 2028
 */

namespace Vtinnovations\GuardianTypo3\Tests\Unit\Infrastructure\Process;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Process\Process;
use Vtinnovations\GuardianTypo3\Domain\Process\CommandRequest;
use Vtinnovations\GuardianTypo3\Infrastructure\Process\SymfonyProcessCommandExecutor;
use Vtinnovations\GuardianTypo3\Infrastructure\Update\ComposerRuntime;
use Vtinnovations\GuardianTypo3\Tests\Unit\Infrastructure\Recovery\FakeWorkingDirectory;

/**
 * The shared Composer/TYPO3 process runner used by online checks, dry runs, real
 * updates, selective updates and recovery vendor rebuilds. These assert the
 * environment overlay (the HOME fix) and that no shell is involved.
 */
final class SymfonyProcessCommandExecutorTest extends TestCase
{
    private string $base;

    protected function setUp(): void
    {
        $this->base = sys_get_temp_dir() . '/guardian-exec-' . bin2hex(random_bytes(6));
    }

    protected function tearDown(): void
    {
        @exec('rm -rf ' . escapeshellarg($this->base));
    }

    private function executor(): SymfonyProcessCommandExecutor
    {
        return new SymfonyProcessCommandExecutor(new ComposerRuntime(new FakeWorkingDirectory($this->base)));
    }

    #[Test]
    public function everyProcessReceivesHomeComposerHomeAndCacheDir(): void
    {
        $request = CommandRequest::create(['/usr/bin/php', '/app/composer.phar', 'update', '--dry-run'], '/app', 600);
        $env = $this->executor()->environmentFor($request);

        self::assertSame($this->base . '/runtime/home', $env['HOME']);
        self::assertSame($this->base . '/runtime/composer-home', $env['COMPOSER_HOME']);
        self::assertSame($this->base . '/runtime/composer-cache', $env['COMPOSER_CACHE_DIR']);
        self::assertSame('1', $env['COMPOSER_NO_INTERACTION']);
        // Building the overlay also creates the private runtime directories.
        self::assertDirectoryExists($this->base . '/runtime/composer-home');
    }

    #[Test]
    public function existingSafeRequestEnvironmentIsPreservedAndWins(): void
    {
        // Simulate inherited safe values (PATH, CA bundle, a Composer auth var).
        $request = CommandRequest::create(['/usr/bin/php', '/app/composer.phar', '--version'], '/app', 60)
            ->withEnv('PATH', '/custom/bin')
            ->withEnv('COMPOSER_AUTH', '{"http-basic":{}}');

        $env = $this->executor()->environmentFor($request);

        self::assertSame('/custom/bin', $env['PATH'], 'PATH is preserved');
        self::assertSame('{"http-basic":{}}', $env['COMPOSER_AUTH'], 'auth is preserved, not stripped');
        self::assertArrayHasKey('HOME', $env);
        self::assertArrayHasKey('COMPOSER_HOME', $env);
    }

    #[Test]
    public function withoutARuntimeItStillMergesNonInteractiveDefaultsOnly(): void
    {
        $executor = new SymfonyProcessCommandExecutor(); // backward-compatible, no runtime
        $env = $executor->environmentFor(CommandRequest::create(['/usr/bin/php', '--version'], null, 30));

        self::assertSame('1', $env['COMPOSER_NO_INTERACTION']);
        self::assertArrayNotHasKey('HOME', $env);
    }

    #[Test]
    public function processIsBuiltFromAnArgvArrayWithNoShellInterpolation(): void
    {
        $request = CommandRequest::create(['/usr/bin/php', '/app/composer.phar', 'update', 'a b; rm -rf /'], '/app', 60);

        $build = new \ReflectionMethod(SymfonyProcessCommandExecutor::class, 'build');
        $build->setAccessible(true);
        /** @var Process $process */
        $process = $build->invoke($this->executor(), $request);

        $commandLine = $process->getCommandLine();
        // The dangerous argument is present but shell-escaped as a single token,
        // and no environment assignment is inlined into the command string.
        self::assertStringContainsString('composer.phar', $commandLine);
        self::assertStringNotContainsString('HOME=', $commandLine);
        self::assertStringNotContainsString('COMPOSER_HOME=', $commandLine);
        // A shell would split "a b; rm -rf /"; Process keeps it as one escaped arg.
        self::assertMatchesRegularExpression('/rm -rf/', $commandLine);
    }
}
