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
 * Immutable record of the last execution of a schedule slot. Used by the
 * evaluator to decide whether a slot is due, and by the dashboard to show
 * "last run / next run". A null {@see self::$lastRun} means "never run".
 */
final class ScheduleRun
{
    public function __construct(
        public readonly ?\DateTimeImmutable $lastRun,
        public readonly string $lastStatus = '',
        public readonly string $lastMessage = '',
    ) {
    }

    public static function never(): self
    {
        return new self(null);
    }

    /**
     * @param array<string, mixed>|null $data
     */
    public static function fromArray(?array $data): self
    {
        if ($data === null || empty($data['last_run'])) {
            return self::never();
        }

        $lastRun = null;
        try {
            $lastRun = new \DateTimeImmutable((string) $data['last_run']);
        } catch (\Throwable) {
            $lastRun = null;
        }

        return new self(
            lastRun: $lastRun,
            lastStatus: (string) ($data['last_status'] ?? ''),
            lastMessage: (string) ($data['last_message'] ?? ''),
        );
    }

    public function hasRun(): bool
    {
        return $this->lastRun !== null;
    }
}
