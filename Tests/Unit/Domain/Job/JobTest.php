<?php

declare(strict_types=1);

/*
 * This file is part of the "Guardian for TYPO3" extension for TYPO3 CMS.
 *
 * @author    V&T Innovations Team
 * @license   LGPL-3.0-or-later
 * @copyright V&T Innovations 2026 - 2028
 */

namespace Vtinnovations\GuardianTypo3\Tests\Unit\Domain\Job;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Vtinnovations\GuardianTypo3\Domain\Exception\GuardianException;
use Vtinnovations\GuardianTypo3\Domain\Job\Job;
use Vtinnovations\GuardianTypo3\Domain\Job\JobStatus;
use Vtinnovations\GuardianTypo3\Domain\Job\JobType;

final class JobTest extends TestCase
{
    private \DateTimeImmutable $now;

    protected function setUp(): void
    {
        $this->now = new \DateTimeImmutable('2026-05-04T12:00:00+00:00');
    }

    private function queued(): Job
    {
        return Job::queue('20260504-120000-abcd', JobType::DryRun, ['backup', 'composer'], $this->now);
    }

    #[Test]
    public function queuedJobIsNotFinished(): void
    {
        $job = $this->queued();

        self::assertSame(JobStatus::Queued, $job->status);
        self::assertFalse($job->isFinished());
    }

    #[Test]
    public function happyPathTransitionsQueuedToRunningToSucceeded(): void
    {
        $job = $this->queued()
            ->start('backup', $this->now)
            ->advanceTo('composer')
            ->succeed($this->now);

        self::assertSame(JobStatus::Succeeded, $job->status);
        self::assertTrue($job->isFinished());
        self::assertNull($job->currentStep);
    }

    #[Test]
    public function failureRecordsFailedStepAndError(): void
    {
        $job = $this->queued()
            ->start('backup', $this->now)
            ->fail('composer', 'composer exited with code 1', $this->now);

        self::assertSame(JobStatus::Failed, $job->status);
        self::assertSame('composer', $job->failedStep);
        self::assertSame('composer exited with code 1', $job->error);
    }

    #[Test]
    public function cannotSucceedWhileStillQueued(): void
    {
        $this->expectException(GuardianException::class);
        $this->queued()->succeed($this->now);
    }

    #[Test]
    public function cannotTransitionOutOfTerminalState(): void
    {
        $job = $this->queued()->start('backup', $this->now)->succeed($this->now);

        $this->expectException(GuardianException::class);
        $job->fail('anything', 'nope', $this->now);
    }

    #[Test]
    public function cannotAdvanceAJobThatIsNotRunning(): void
    {
        $this->expectException(GuardianException::class);
        $this->queued()->advanceTo('composer');
    }

    #[Test]
    public function arrayRoundTripPreservesState(): void
    {
        $job = $this->queued()->start('backup', $this->now);
        $restored = Job::fromArray($job->toArray());

        self::assertSame($job->id, $restored->id);
        self::assertSame($job->status, $restored->status);
        self::assertSame($job->type, $restored->type);
        self::assertSame($job->steps, $restored->steps);
        self::assertSame($job->currentStep, $restored->currentStep);
    }
}
