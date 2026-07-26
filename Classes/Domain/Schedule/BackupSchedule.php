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
 * Immutable description of a single scheduled backup slot (e.g. "mini" or
 * "full"). Carries only scheduling intent — not the mechanics of taking a
 * backup, which is a later phase. Values are clamped to valid ranges on
 * construction so downstream evaluation never has to defend against garbage.
 */
final class BackupSchedule
{
    public function __construct(
        public readonly bool $enabled,
        public readonly ScheduleFrequency $frequency,
        public readonly int $hour,
        public readonly int $minute,
        /** ISO weekday used for weekly frequency: 0 = Sunday … 6 = Saturday. */
        public readonly int $weekday,
        /** Day of month (1-28) used for monthly frequency. */
        public readonly int $dayOfMonth,
        public readonly int $retention,
    ) {
    }

    /**
     * @param array<string, mixed> $data
     */
    public static function fromArray(array $data): self
    {
        [$hour, $minute] = self::parseTime((string) ($data['time'] ?? '03:00'));

        return new self(
            enabled: (bool) ($data['enabled'] ?? false),
            frequency: ScheduleFrequency::fromString((string) ($data['frequency'] ?? 'daily')),
            hour: $hour,
            minute: $minute,
            weekday: self::clamp((int) ($data['weekday'] ?? 1), 0, 6),
            dayOfMonth: self::clamp((int) ($data['day_of_month'] ?? 1), 1, 28),
            retention: self::clamp((int) ($data['retention'] ?? 7), 1, 999),
        );
    }

    public function time(): string
    {
        return sprintf('%02d:%02d', $this->hour, $this->minute);
    }

    /**
     * Getter alias so Fluid templates can read the formatted time via
     * {schedule.formattedTime}.
     */
    public function getFormattedTime(): string
    {
        return $this->time();
    }

    /**
     * Getter alias so Fluid templates can read the frequency string via
     * {schedule.frequencyValue}.
     */
    public function getFrequencyValue(): string
    {
        return $this->frequency->value;
    }

    /**
     * @return array{0: int, 1: int}
     */
    private static function parseTime(string $time): array
    {
        if (preg_match('/^([01]\d|2[0-3]):([0-5]\d)$/', $time, $m) === 1) {
            return [(int) $m[1], (int) $m[2]];
        }

        return [3, 0];
    }

    private static function clamp(int $value, int $min, int $max): int
    {
        return max($min, min($max, $value));
    }
}
