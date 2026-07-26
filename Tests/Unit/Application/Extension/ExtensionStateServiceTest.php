<?php

declare(strict_types=1);

/*
 * This file is part of the "Guardian for TYPO3" extension for TYPO3 CMS.
 *
 * @author    V&T Innovations Team
 * @license   LGPL-3.0-or-later
 * @copyright V&T Innovations 2026 - 2028
 */

namespace Vtinnovations\GuardianTypo3\Tests\Unit\Application\Extension;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Vtinnovations\GuardianTypo3\Application\Contract\ProjectEnvironmentInterface;
use Vtinnovations\GuardianTypo3\Application\Contract\SystemLoggerInterface;
use Vtinnovations\GuardianTypo3\Application\Contract\Typo3ExtensionStateInterface;
use Vtinnovations\GuardianTypo3\Application\Extension\ExtensionStateService;
use Vtinnovations\GuardianTypo3\Application\Update\PackageManager;
use Vtinnovations\GuardianTypo3\Domain\Clock\ClockInterface;
use Vtinnovations\GuardianTypo3\Domain\Exception\GuardianException;
use Vtinnovations\GuardianTypo3\Domain\Filesystem\PathNormalizer;
use Vtinnovations\GuardianTypo3\Infrastructure\Composer\PathRepositoryInspector;
use Vtinnovations\GuardianTypo3\Infrastructure\Update\UpdateJobStore;
use Vtinnovations\GuardianTypo3\Tests\Unit\Infrastructure\Recovery\FakeWorkingDirectory;

final class ExtensionStateServiceTest extends TestCase
{
    private string $project;
    /** @var list<string> */
    public array $deactivated = [];
    /** @var list<string> */
    public array $activated = [];
    /** @var array<string, bool> */
    private array $inactive = [];

    protected function setUp(): void
    {
        $this->project = sys_get_temp_dir() . '/guardian-ess-' . bin2hex(random_bytes(6));
        mkdir($this->project . '/vendor/composer', 0o755, true);
        file_put_contents($this->project . '/composer.json', json_encode(['require' => ['acme/blog' => '^1.0', 'psr/log' => '^3.0']]));
        file_put_contents($this->project . '/vendor/composer/installed.json', json_encode(['packages' => [
            ['name' => 'typo3/cms-core', 'type' => 'typo3-cms-framework', 'version' => 'v13.4.9', 'install-path' => '../typo3/cms-core'],
            ['name' => 'vtinnovations/guardian-typo3', 'type' => 'typo3-cms-extension', 'version' => '1.0.0', 'install-path' => '../vtinnovations/guardian-typo3', 'extra' => ['typo3/cms' => ['extension-key' => 'guardian_typo3']]],
            ['name' => 'acme/blog', 'type' => 'typo3-cms-extension', 'version' => '1.2.0', 'install-path' => '../acme/blog', 'extra' => ['typo3/cms' => ['extension-key' => 'acme_blog']]],
            ['name' => 'psr/log', 'type' => 'library', 'version' => '3.0.0', 'install-path' => '../psr/log'],
        ]]));
    }

    protected function tearDown(): void
    {
        @exec('rm -rf ' . escapeshellarg($this->project));
    }

    private function service(): ExtensionStateService
    {
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
        $clock = new class implements ClockInterface {
            public function now(): \DateTimeImmutable { return new \DateTimeImmutable('2026-07-26T00:00:00+00:00'); }
        };
        $store = new UpdateJobStore(new FakeWorkingDirectory($this->project . '/wd'), $clock);
        $state = new class($this->inactive, $this) implements Typo3ExtensionStateInterface {
            /** @param array<string, bool> $inactive */
            public function __construct(private array $inactive, private ExtensionStateServiceTest $probe) {}
            public function isAvailable(): bool { return true; }
            public function isActive(string $extensionKey): bool { return !isset($this->inactive[$extensionKey]); }
            public function isProtected(string $extensionKey): bool { return false; }
            public function deactivate(string $extensionKey): void { $this->probe->deactivated[] = $extensionKey; }
            public function activate(string $extensionKey): void { $this->probe->activated[] = $extensionKey; }
        };
        $manager = new PackageManager($env, new PathRepositoryInspector(new PathNormalizer()), $store, $state);
        $logger = new class implements SystemLoggerInterface {
            public function info(string $message, string $channel = 'guardian'): void {}
            public function warning(string $message, string $channel = 'guardian'): void {}
            public function error(string $message, string $channel = 'guardian'): void {}
        };

        return new ExtensionStateService($manager, $state, $logger);
    }

    #[Test]
    public function disablesAnEligibleThirdPartyExtension(): void
    {
        $result = $this->service()->disable('acme/blog');
        self::assertSame('acme_blog', $result['extension_key']);
        self::assertFalse($result['active']);
        self::assertSame(['acme_blog'], $this->deactivated);
    }

    #[Test]
    public function refusesToDisableAnOrdinaryLibrary(): void
    {
        $this->expectException(GuardianException::class);
        $this->expectExceptionMessage('not_an_extension');
        $this->service()->disable('psr/log');
    }

    #[Test]
    public function refusesToDisableTheCore(): void
    {
        $this->expectException(GuardianException::class);
        $this->expectExceptionMessage('core_cannot_disable');
        $this->service()->disable('typo3/cms-core');
    }

    #[Test]
    public function refusesToDisableGuardianItself(): void
    {
        $this->expectException(GuardianException::class);
        $this->expectExceptionMessage('guardian_self');
        $this->service()->disable('vtinnovations/guardian-typo3');
    }

    #[Test]
    public function enableRejectsAnAlreadyActiveExtension(): void
    {
        $this->expectException(GuardianException::class);
        $this->expectExceptionMessage('already_active');
        $this->service()->enable('acme/blog');
    }

    #[Test]
    public function enablesAPreviouslyDisabledExtension(): void
    {
        $this->inactive = ['acme_blog' => true];
        $result = $this->service()->enable('acme/blog');
        self::assertTrue($result['active']);
        self::assertSame(['acme_blog'], $this->activated);
    }
}
