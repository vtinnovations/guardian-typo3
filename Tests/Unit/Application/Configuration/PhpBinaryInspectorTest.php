<?php

declare(strict_types=1);

/*
 * This file is part of the "Guardian for TYPO3" extension for TYPO3 CMS.
 *
 * @author    V&T Innovations Team
 * @license   LGPL-3.0-or-later
 * @copyright V&T Innovations 2026 - 2028
 */

namespace Vtinnovations\GuardianTypo3\Tests\Unit\Application\Configuration;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Vtinnovations\GuardianTypo3\Application\Configuration\PhpBinaryInspector;
use Vtinnovations\GuardianTypo3\Application\Configuration\RuntimeConfigurationService;
use Vtinnovations\GuardianTypo3\Application\Contract\ProjectEnvironmentInterface;
use Vtinnovations\GuardianTypo3\Infrastructure\Configuration\JsonRuntimeConfigurationRepository;
use Vtinnovations\GuardianTypo3\Infrastructure\Process\SymfonyProcessCommandExecutor;
use Vtinnovations\GuardianTypo3\Tests\Unit\Infrastructure\Recovery\FakeWorkingDirectory;

final class PhpBinaryInspectorTest extends TestCase
{
    private string $base;

    protected function setUp(): void
    {
        $this->base = sys_get_temp_dir() . '/guardian-php-' . bin2hex(random_bytes(6));
        mkdir($this->base, 0o755, true);
    }

    protected function tearDown(): void
    {
        @exec('rm -rf ' . escapeshellarg($this->base));
    }

    private function inspector(string $typo3 = '13.4.9'): PhpBinaryInspector
    {
        $env = new class($typo3) implements ProjectEnvironmentInterface {
            public function __construct(private readonly string $v) {}
            public function typo3Version(): string { return $this->v; }
            public function projectPath(): string { return '/tmp'; }
            public function varPath(): string { return '/tmp/var'; }
            public function publicPath(): string { return '/tmp/public'; }
            public function isComposerMode(): bool { return true; }
            public function phpVersion(): string { return \PHP_VERSION; }
            public function loadedPhpExtensions(): array { return []; }
        };
        $config = new RuntimeConfigurationService(new JsonRuntimeConfigurationRepository(new FakeWorkingDirectory($this->base . '/wd')));

        return new PhpBinaryInspector(new SymfonyProcessCommandExecutor(), $env, $config);
    }

    #[Test]
    public function rejectsARelativePath(): void
    {
        self::assertSame('not_absolute', $this->inspector()->test('bin/php')['error_code']);
    }

    #[Test]
    public function rejectsANullByte(): void
    {
        self::assertSame('null_byte', $this->inspector()->test("/usr/bin/php\0/etc/passwd")['error_code']);
    }

    #[Test]
    public function rejectsShellSyntaxAndExtraArguments(): void
    {
        self::assertSame('shell_syntax', $this->inspector()->test('/usr/bin/php -r "system(1)"')['error_code']);
        self::assertSame('shell_syntax', $this->inspector()->test('/usr/bin/php;rm')['error_code']);
    }

    #[Test]
    public function rejectsAMissingFile(): void
    {
        self::assertSame('not_found', $this->inspector()->test('/no/such/guardian-php-binary')['error_code']);
    }

    #[Test]
    public function rejectsANonExecutableRegularFile(): void
    {
        $file = $this->base . '/not-exec';
        file_put_contents($file, "#!/bin/sh\n");
        chmod($file, 0o644);
        self::assertSame('not_executable', $this->inspector()->test($file)['error_code']);
    }

    #[Test]
    public function acceptsTheRunningPhpBinaryAndParsesItsVersion(): void
    {
        if (\PHP_BINARY === '' || !is_executable(\PHP_BINARY)) {
            self::markTestSkipped('PHP_BINARY is not usable in this environment.');
        }
        $result = $this->inspector()->test(\PHP_BINARY);

        self::assertTrue($result['valid'], (string) ($result['error_code'] ?? ''));
        self::assertMatchesRegularExpression('/^\d+\.\d+\.\d+$/', (string) $result['version']);
        self::assertTrue($result['satisfies_guardian']);
        self::assertSame(\PHP_MAJOR_VERSION . '.' . \PHP_MINOR_VERSION, substr((string) $result['version'], 0, \strlen(\PHP_MAJOR_VERSION . '.' . \PHP_MINOR_VERSION)));
    }

    #[Test]
    public function typo3FourteenRequiresPhp83(): void
    {
        self::assertSame('8.3.0', $this->inspector('14.0.0')->detect()['typo3_min']);
        self::assertSame('8.2.0', $this->inspector('13.4.9')->detect()['typo3_min']);
    }

    #[Test]
    public function detectReportsTheGuardianMinimumAndCandidates(): void
    {
        $detect = $this->inspector()->detect();
        self::assertSame('8.2.0', $detect['guardian_min']);
        self::assertArrayHasKey('candidates', $detect);
        self::assertIsArray($detect['candidates']);
    }
}
