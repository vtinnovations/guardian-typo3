<?php

declare(strict_types=1);

/*
 * This file is part of the "Guardian for TYPO3" extension for TYPO3 CMS.
 *
 * @author    V&T Innovations Team
 * @license   LGPL-3.0-or-later
 * @copyright V&T Innovations 2026 - 2028
 */

namespace Vtinnovations\GuardianTypo3\Application\Contract;

/**
 * Read/write persistence for the scheduled-backup configuration (mini + full
 * profiles, notification settings) and the last-run state. Ported from the
 * audited Contao ScheduleConfig/ScheduleState. The stored format is JSON under
 * Guardian's working directory.
 */
interface ScheduleConfigStoreInterface
{
    /**
     * @return array<string, mixed> normalised config (always valid)
     */
    public function loadConfig(): array;

    /**
     * Validates and persists the config, returning the normalised result.
     *
     * @param array<string, mixed> $raw
     * @return array<string, mixed>
     */
    public function saveConfig(array $raw): array;

    /**
     * @return array{mini: array<string, mixed>|null, full: array<string, mixed>|null}
     */
    public function loadState(): array;

    /**
     * @param 'mini'|'full' $type
     */
    public function recordRun(string $type, string $status, string $message, ?string $backupId): void;
}
