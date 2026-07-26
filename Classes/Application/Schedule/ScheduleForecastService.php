<?php

declare(strict_types=1);

/*
 * This file is part of the "Guardian for TYPO3" extension for TYPO3 CMS.
 *
 * @author    V&T Innovations Team
 * @license   LGPL-3.0-or-later
 * @copyright V&T Innovations 2026 - 2028
 */

namespace Vtinnovations\GuardianTypo3\Application\Schedule;

use Vtinnovations\GuardianTypo3\Application\Contract\ScheduleRepositoryInterface;
use Vtinnovations\GuardianTypo3\Domain\Clock\ClockInterface;
use Vtinnovations\GuardianTypo3\Domain\Schedule\ScheduleEvaluator;

/**
 * Produces read-only {@see ScheduleForecast}s for the dashboard by combining the
 * stored schedule configuration/state with the pure {@see ScheduleEvaluator}.
 * It computes "due" and "next run" but never triggers a backup — execution is a
 * later phase.
 */
final class ScheduleForecastService
{
    private const SLOTS = ['mini', 'full'];

    public function __construct(
        private readonly ScheduleRepositoryInterface $repository,
        private readonly ScheduleEvaluator $evaluator,
        private readonly ClockInterface $clock,
    ) {
    }

    /**
     * @return list<ScheduleForecast>
     */
    public function forecastAll(): array
    {
        $now = $this->clock->now();
        $forecasts = [];

        foreach (self::SLOTS as $slot) {
            $schedule = $this->repository->loadSchedule($slot);
            $state = $this->repository->loadState($slot);

            $forecasts[] = new ScheduleForecast(
                slot: $slot,
                schedule: $schedule,
                lastRun: $state,
                due: $this->evaluator->isDue($schedule, $state, $now),
                nextRun: $this->evaluator->nextOccurrence($schedule, $state, $now),
            );
        }

        return $forecasts;
    }
}
