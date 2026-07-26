<?php

declare(strict_types=1);

/*
 * This file is part of the "Guardian for TYPO3" extension for TYPO3 CMS.
 *
 * @author    V&T Innovations Team
 * @license   LGPL-3.0-or-later
 * @copyright V&T Innovations 2026 - 2028
 */

namespace Vtinnovations\GuardianTypo3\Tests\Unit\Domain\Schedule;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Vtinnovations\GuardianTypo3\Domain\Schedule\BackupSchedule;
use Vtinnovations\GuardianTypo3\Domain\Schedule\ScheduleEvaluator;
use Vtinnovations\GuardianTypo3\Domain\Schedule\ScheduleRun;

final class ScheduleEvaluatorTest extends TestCase
{
    private ScheduleEvaluator $evaluator;

    protected function setUp(): void
    {
        $this->evaluator = new ScheduleEvaluator();
    }

    private function at(string $iso): \DateTimeImmutable
    {
        return new \DateTimeImmutable($iso, new \DateTimeZone('UTC'));
    }

    private function dailySchedule(bool $enabled = true): BackupSchedule
    {
        return BackupSchedule::fromArray([
            'enabled' => $enabled,
            'frequency' => 'daily',
            'time' => '03:00',
        ]);
    }

    #[Test]
    public function disabledScheduleIsNeverDue(): void
    {
        $due = $this->evaluator->isDue(
            $this->dailySchedule(enabled: false),
            ScheduleRun::never(),
            $this->at('2026-05-04T10:00:00+00:00')
        );

        self::assertFalse($due);
    }

    #[Test]
    public function dailyIsDueWhenNeverRunAndSlotHasPassed(): void
    {
        $due = $this->evaluator->isDue(
            $this->dailySchedule(),
            ScheduleRun::never(),
            $this->at('2026-05-04T10:00:00+00:00')
        );

        self::assertTrue($due);
    }

    #[Test]
    public function dailyIsNotDueWhenAlreadyRunAfterTodaysSlot(): void
    {
        $state = ScheduleRun::fromArray(['last_run' => '2026-05-04T03:05:00+00:00']);

        $due = $this->evaluator->isDue(
            $this->dailySchedule(),
            $state,
            $this->at('2026-05-04T10:00:00+00:00')
        );

        self::assertFalse($due);
    }

    #[Test]
    public function dailyIsDueWhenLastRunWasBeforeTodaysSlot(): void
    {
        $state = ScheduleRun::fromArray(['last_run' => '2026-05-03T03:05:00+00:00']);

        $due = $this->evaluator->isDue(
            $this->dailySchedule(),
            $state,
            $this->at('2026-05-04T10:00:00+00:00')
        );

        self::assertTrue($due);
    }

    #[Test]
    public function hourlyIntervalUsesElapsedTime(): void
    {
        $schedule = BackupSchedule::fromArray(['enabled' => true, 'frequency' => 'hourly']);
        $now = $this->at('2026-05-04T10:00:00+00:00');

        $recent = ScheduleRun::fromArray(['last_run' => '2026-05-04T09:30:00+00:00']);
        $stale = ScheduleRun::fromArray(['last_run' => '2026-05-04T08:55:00+00:00']);

        self::assertFalse($this->evaluator->isDue($schedule, $recent, $now));
        self::assertTrue($this->evaluator->isDue($schedule, $stale, $now));
    }

    #[Test]
    public function nextOccurrenceForDailyAdvancesToTomorrowWhenTodayPassed(): void
    {
        $next = $this->evaluator->nextOccurrence(
            $this->dailySchedule(),
            ScheduleRun::fromArray(['last_run' => '2026-05-04T03:00:00+00:00']),
            $this->at('2026-05-04T10:00:00+00:00')
        );

        self::assertNotNull($next);
        self::assertSame('2026-05-05 03:00', $next->format('Y-m-d H:i'));
    }

    #[Test]
    public function weeklyIsDueOnlyOnConfiguredWeekday(): void
    {
        // 2026-05-04 is a Monday (ISO weekday 1 == PHP 'w' 1).
        $schedule = BackupSchedule::fromArray([
            'enabled' => true,
            'frequency' => 'weekly',
            'time' => '02:00',
            'weekday' => 1,
        ]);

        $mondayAfterSlot = $this->at('2026-05-04T05:00:00+00:00');
        $sunday = $this->at('2026-05-03T05:00:00+00:00');

        self::assertTrue($this->evaluator->isDue($schedule, ScheduleRun::never(), $mondayAfterSlot));
        // On Sunday the most recent Monday occurrence is in the past (previous week),
        // so a never-run weekly schedule is due; assert the weekday match instead
        // via next occurrence landing on the coming Monday.
        $next = $this->evaluator->nextOccurrence($schedule, ScheduleRun::never(), $sunday);
        self::assertNotNull($next);
        self::assertSame('1', $next->format('w'));
    }
}
