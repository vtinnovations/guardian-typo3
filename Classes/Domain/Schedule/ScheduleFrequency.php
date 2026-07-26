<?php

declare(strict_types=1);

/*
 * This file is part of the "Guardian for TYPO3" extension for TYPO3 CMS.
 *
 * @author    V&T Innovations Team
 * @license   LGPL-3.0-or-later
 * @copyright V&T Innovations 2026 - 2028
 */

namespace Vtinnovations\GuardianTypo3\Domain\Schedule;

/**
 * Supported backup schedule frequencies.
 *
 * Sub-hourly frequencies (5min/15min/hourly) are evaluated by elapsed interval;
 * daily/weekly/monthly are evaluated by wall-clock "slot" occurrence. The
 * {@see ScheduleEvaluator} branches on {@see self::intervalSeconds()} being
 * non-null to choose the strategy.
 */
enum ScheduleFrequency: string
{
    case FiveMinutes = '5min';
    case FifteenMinutes = '15min';
    case Hourly = 'hourly';
    case Daily = 'daily';
    case Weekly = 'weekly';
    case Monthly = 'monthly';

    public static function fromString(string $value, self $fallback = self::Daily): self
    {
        return self::tryFrom($value) ?? $fallback;
    }

    /**
     * Interval length in seconds for interval-based frequencies, or null for
     * slot-based (daily/weekly/monthly) frequencies.
     */
    public function intervalSeconds(): ?int
    {
        return match ($this) {
            self::FiveMinutes => 300,
            self::FifteenMinutes => 900,
            self::Hourly => 3600,
            default => null,
        };
    }

    public function isIntervalBased(): bool
    {
        return $this->intervalSeconds() !== null;
    }
}
