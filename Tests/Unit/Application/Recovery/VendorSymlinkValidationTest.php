<?php

declare(strict_types=1);

/*
 * This file is part of the "Guardian for TYPO3" extension for TYPO3 CMS.
 *
 * @author    V&T Innovations Team
 * @license   LGPL-3.0-or-later
 * @copyright V&T Innovations 2026 - 2028
 */

namespace Vtinnovations\GuardianTypo3\Tests\Unit\Application\Recovery;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Vtinnovations\GuardianTypo3\Application\Contract\ProjectEnvironmentInterface;
use Vtinnovations\GuardianTypo3\Application\Recovery\VendorRecoveryService;
use Vtinnovations\GuardianTypo3\Domain\Exception\GuardianException;
use Vtinnovations\GuardianTypo3\Domain\Filesystem\PathNormalizer;

/**
 * Proves the staged-vendor symlink validator accepts the relative symlinks
 * Composer legitimately creates (bin proxies + local path-repository links that
 * point inside the project but outside vendor/) while still rejecting arbitrary
 * external symlinks that escape the project root.
 */
final class VendorSymlinkValidationTest extends TestCase
{
    private string $project;
    private string $staged;

    protected function setUp(): void
    {
        $this->project = sys_get_temp_dir() . '/guardian-vsl-' . bin2hex(random_bytes(6));
        $this->staged = $this->project . '/.staging/vendor';
        mkdir($this->staged . '/bin', 0o755, true);
        mkdir($this->staged . '/acme', 0o755, true);
    }

    protected function tearDown(): void
    {
        @exec('rm -rf ' . escapeshellarg($this->project));
    }

    /**
     * Invokes the private validator with only the two properties it needs, so the
     * heavy dependency chain is not required.
     */
    private function validate(): int
    {
        $ref = new \ReflectionClass(VendorRecoveryService::class);
        $service = $ref->newInstanceWithoutConstructor();

        $env = new class($this->project) implements ProjectEnvironmentInterface {
            public function __construct(private readonly string $p) {}
            public function typo3Version(): string { return '13.4.9'; }
            public function projectPath(): string { return $this->p; }
            public function varPath(): string { return $this->p . '/var'; }
            public function publicPath(): string { return $this->p . '/public'; }
            public function isComposerMode(): bool { return true; }
            public function phpVersion(): string { return \PHP_VERSION; }
            public function loadedPhpExtensions(): array { return []; }
        };
        $ref->getProperty('environment')->setValue($service, $env);
        $ref->getProperty('pathNormalizer')->setValue($service, new PathNormalizer());

        $method = $ref->getMethod('assertNoUnsafeSymlinks');
        $method->setAccessible(true);

        return (int) $method->invoke($service, $this->staged);
    }

    #[Test]
    public function acceptsNormalComposerBinAndPathRepositorySymlinks(): void
    {
        // bin proxy inside vendor
        symlink('../typo3/cms-cli/typo3', $this->staged . '/bin/typo3');
        // local path-repository link: inside the project, OUTSIDE vendor
        symlink('../../packages/ext', $this->staged . '/acme/ext');

        self::assertSame(2, $this->validate(), 'both legitimate relative symlinks must be accepted');
    }

    #[Test]
    public function rejectsRelativeSymlinkEscapingTheProjectRoot(): void
    {
        symlink('../../../../../../../../etc/passwd', $this->staged . '/evil');

        $this->expectException(GuardianException::class);
        $this->expectExceptionMessageMatches('/vendor\/evil/');
        $this->validate();
    }

    #[Test]
    public function rejectsAbsoluteSymlinkOutsideTheProject(): void
    {
        symlink('/etc/passwd', $this->staged . '/bin/evil');

        $this->expectException(GuardianException::class);
        $this->expectExceptionMessageMatches('/Escapes the project root/');
        $this->validate();
    }

    #[Test]
    public function danglingRelativeSymlinkInsideProjectIsAccepted(): void
    {
        // Target need not exist — validation is lexical, not realpath-based.
        symlink('../typo3/cms-core/bin/typo3', $this->staged . '/bin/typo3');

        self::assertSame(1, $this->validate());
    }
}
