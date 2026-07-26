<?php

declare(strict_types=1);

/*
 * This file is part of the "Guardian for TYPO3" extension for TYPO3 CMS.
 *
 * @author    V&T Innovations Team
 * @license   LGPL-3.0-or-later
 * @copyright V&T Innovations 2026 - 2028
 */

namespace Vtinnovations\GuardianTypo3\Tests\Unit\Application\SelfMaintenance;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Vtinnovations\GuardianTypo3\Application\Contract\DeferredWorkerSpawnerInterface;
use Vtinnovations\GuardianTypo3\Application\Contract\SystemLoggerInterface;
use Vtinnovations\GuardianTypo3\Application\Contract\Typo3ExtensionStateInterface;
use Vtinnovations\GuardianTypo3\Application\SelfMaintenance\SelfMaintenanceService;
use Vtinnovations\GuardianTypo3\Domain\Clock\ClockInterface;
use Vtinnovations\GuardianTypo3\Domain\Exception\GuardianException;
use Vtinnovations\GuardianTypo3\Infrastructure\SelfMaintenance\SelfMaintenanceStore;
use Vtinnovations\GuardianTypo3\Tests\Unit\Infrastructure\Recovery\FakeWorkingDirectory;

final class SelfMaintenanceServiceTest extends TestCase
{
    private string $base;
    private SelfMaintenanceStore $store;
    /** @var list<string> */
    public array $spawned = [];
    /** @var list<string> */
    public array $deactivated = [];
    private bool $failDeactivate = false;

    protected function setUp(): void
    {
        $this->base = sys_get_temp_dir() . '/guardian-selfmaint-' . bin2hex(random_bytes(6));
        $this->store = new SelfMaintenanceStore(new FakeWorkingDirectory($this->base));
    }

    protected function tearDown(): void
    {
        @exec('rm -rf ' . escapeshellarg($this->base));
    }

    private function service(): SelfMaintenanceService
    {
        $spawner = new class($this) implements DeferredWorkerSpawnerInterface {
            public function __construct(private SelfMaintenanceServiceTest $probe) {}
            public function spawn(string $jobId): void { $this->probe->spawned[] = $jobId; }
        };
        $state = new class($this) implements Typo3ExtensionStateInterface {
            public function __construct(private SelfMaintenanceServiceTest $probe) {}
            public function isAvailable(): bool { return true; }
            public function isActive(string $extensionKey): bool { return true; }
            public function isProtected(string $extensionKey): bool { return false; }
            public function deactivate(string $extensionKey): void
            {
                $this->probe->recordDeactivate($extensionKey);
            }
            public function activate(string $extensionKey): void {}
        };
        $clock = new class implements ClockInterface {
            public function now(): \DateTimeImmutable { return new \DateTimeImmutable('2026-07-26T10:00:00+00:00'); }
        };
        $logger = new class implements SystemLoggerInterface {
            public function info(string $message, string $context = ''): void {}
            public function warning(string $message, string $context = ''): void {}
            public function error(string $message, string $context = ''): void {}
        };

        return new SelfMaintenanceService($this->store, $spawner, $state, $clock, $logger);
    }

    public function recordDeactivate(string $key): void
    {
        $this->deactivated[] = $key;
        if ($this->failDeactivate) {
            throw new GuardianException('disable_unsupported');
        }
    }

    #[Test]
    public function requestDisableQueuesAFixedGuardianJobAndSpawnsTheWorker(): void
    {
        $result = $this->service()->requestDisable('editor');
        self::assertMatchesRegularExpression('/^\d{8}-\d{6}-[a-f0-9]{8}$/', $result['jobId']);
        self::assertSame([$result['jobId']], $this->spawned);

        $job = $this->store->job();
        self::assertSame('disable', $job['action']);
        self::assertSame('vtinnovations/guardian-typo3', $job['package']);
        self::assertSame('queued', $this->store->status()['status']);
    }

    #[Test]
    public function runDisableDeactivatesGuardianViaTheSupportedApi(): void
    {
        $service = $this->service();
        $jobId = $service->requestDisable('editor')['jobId'];
        $service->runDisable($jobId);

        self::assertSame(['guardian_typo3'], $this->deactivated);
        self::assertSame('succeeded', $this->store->status()['status']);
    }

    #[Test]
    public function selfDisableFailureLeavesGuardianEnabledAndRecordsFailure(): void
    {
        $this->failDeactivate = true;
        $service = $this->service();
        $jobId = $service->requestDisable('editor')['jobId'];
        $service->runDisable($jobId);

        self::assertSame('failed', $this->store->status()['status']);
        self::assertNotSame('', (string) $this->store->status()['message']);
    }

    #[Test]
    public function theWorkerRefusesAJobThatIsNotTheGuardianDisableOperation(): void
    {
        $service = $this->service();
        // Tamper with the persisted job to target another package.
        $this->store->saveJob(['id' => '20260726-100000-deadbeef', 'action' => 'disable', 'package' => 'evil/package']);

        try {
            $service->runDisable('20260726-100000-deadbeef');
            self::fail('expected invalid_self_target');
        } catch (GuardianException $e) {
            self::assertSame('invalid_self_target', $e->getMessage());
        }
        self::assertSame([], $this->deactivated, 'nothing must be deactivated for a non-Guardian job');
    }

    #[Test]
    public function selfMaintenanceEndpointOnlyAcceptsTheGuardianIdentity(): void
    {
        $this->expectException(GuardianException::class);
        $this->expectExceptionMessage('invalid_self_target');
        $this->service()->assertGuardianIdentity('doctrine/dbal');
    }

    #[Test]
    public function guardianIdentityIsAccepted(): void
    {
        $this->service()->assertGuardianIdentity(SelfMaintenanceService::GUARDIAN_PACKAGE);
        $this->addToAssertionCount(1);
    }

    #[Test]
    public function aRunningSelfMaintenanceBlocksAnotherRequest(): void
    {
        $this->store->saveStatus(['id' => 'x', 'action' => 'disable', 'status' => 'running']);
        $this->expectException(GuardianException::class);
        $this->expectExceptionMessage('self_maintenance_running');
        $this->service()->requestDisable('editor');
    }
}
