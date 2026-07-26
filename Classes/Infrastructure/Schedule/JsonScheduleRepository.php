<?php

declare(strict_types=1);

/*
 * This file is part of the "Guardian for TYPO3" extension for TYPO3 CMS.
 *
 * @author    V&T Innovations Team
 * @license   LGPL-3.0-or-later
 * @copyright V&T Innovations 2026 - 2028
 */

namespace Vtinnovations\GuardianTypo3\Infrastructure\Schedule;

use Vtinnovations\GuardianTypo3\Application\Contract\ScheduleRepositoryInterface;
use Vtinnovations\GuardianTypo3\Application\Contract\WorkingDirectoryProviderInterface;
use Vtinnovations\GuardianTypo3\Domain\Schedule\BackupSchedule;
use Vtinnovations\GuardianTypo3\Domain\Schedule\ScheduleRun;

/**
 * Reads scheduled-backup configuration (schedule.json) and last-run state
 * (schedule_state.json) from Guardian's working directory. Read-only in Phase 1
 * — a missing config yields disabled slots, so the forecast is inert until a
 * later phase wires up configuration and execution.
 */
final class JsonScheduleRepository implements ScheduleRepositoryInterface
{
    private const CONFIG_FILENAME = 'schedule.json';
    private const STATE_FILENAME = 'schedule_state.json';

    public function __construct(
        private readonly WorkingDirectoryProviderInterface $workingDirectory,
    ) {
    }

    public function loadSchedule(string $slot): BackupSchedule
    {
        $config = $this->readJson(self::CONFIG_FILENAME);
        $slotData = \is_array($config[$slot] ?? null) ? $config[$slot] : [];

        return BackupSchedule::fromArray($slotData);
    }

    public function loadState(string $slot): ScheduleRun
    {
        $state = $this->readJson(self::STATE_FILENAME);
        $slotData = \is_array($state[$slot] ?? null) ? $state[$slot] : null;

        return ScheduleRun::fromArray($slotData);
    }

    /**
     * @return array<string, mixed>
     */
    private function readJson(string $filename): array
    {
        $file = $this->workingDirectory->resolve($filename);
        if (!is_file($file)) {
            return [];
        }

        $raw = @file_get_contents($file);
        if ($raw === false) {
            return [];
        }

        $data = json_decode($raw, true);

        return \is_array($data) ? $data : [];
    }
}
