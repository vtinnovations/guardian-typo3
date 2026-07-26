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
use Vtinnovations\GuardianTypo3\Domain\Clock\ClockInterface;
use Vtinnovations\GuardianTypo3\Domain\Job\Job;
use Vtinnovations\GuardianTypo3\Domain\Job\JobType;
use Vtinnovations\GuardianTypo3\Infrastructure\Update\UpdateJobStore;
use Vtinnovations\GuardianTypo3\Tests\Unit\Infrastructure\Recovery\FakeWorkingDirectory;

final class UpdateJobStoreTest extends TestCase
{
    private UpdateJobStore $store;
    private \DateTimeImmutable $now;

    protected function setUp(): void
    {
        $this->now = new \DateTimeImmutable('2026-07-23T10:00:00+00:00');
        $clock = new class($this->now) implements ClockInterface {
            public function __construct(private readonly \DateTimeImmutable $now) {}
            public function now(): \DateTimeImmutable { return $this->now; }
        };
        $base = sys_get_temp_dir() . '/guardian-update-' . bin2hex(random_bytes(6));
        $this->store = new UpdateJobStore(new FakeWorkingDirectory($base), $clock);
    }

    #[Test]
    public function savesAndReloadsTheActiveJob(): void
    {
        $job = Job::queue($this->store->generateId(), JobType::Update, ['safety_backup', 'composer'], $this->now, ['update_mode' => 'full']);
        $this->store->save($job);

        $loaded = $this->store->current();
        self::assertNotNull($loaded);
        self::assertSame($job->id, $loaded->id);
        self::assertSame('full', $loaded->options['update_mode']);
    }

    #[Test]
    public function archivingAFinishedJobClearsTheActiveSlotAndListsIt(): void
    {
        $job = Job::queue($this->store->generateId(), JobType::Update, ['composer'], $this->now)
            ->start('composer', $this->now)
            ->succeed($this->now);
        $this->store->save($job);
        $this->store->archive($job);

        self::assertNull($this->store->current(), 'active slot must be cleared');
        $archive = $this->store->listArchive();
        self::assertNotEmpty($archive);
        self::assertSame($job->id, $archive[0]['id']);
        self::assertNotNull($this->store->getArchived($job->id));
    }

    #[Test]
    public function generatedIdMatchesTheExpectedFormat(): void
    {
        self::assertMatchesRegularExpression('/^\d{8}-\d{6}-[a-f0-9]{8}$/', $this->store->generateId());
    }

    #[Test]
    public function getArchivedRejectsPathTraversalIds(): void
    {
        self::assertNull($this->store->getArchived('../../etc/passwd'));
    }
}
