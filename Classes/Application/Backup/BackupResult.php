<?php

declare(strict_types=1);

/*
 * This file is part of the "Guardian for TYPO3" extension for TYPO3 CMS.
 *
 * @author    V&T Innovations Team
 * @license   LGPL-3.0-or-later
 * @copyright V&T Innovations 2026 - 2028
 */

namespace Vtinnovations\GuardianTypo3\Application\Backup;

/**
 * Immutable outcome of a backup run: the persisted manifest plus the log lines
 * and the count of retention deletions. Contains no filesystem paths that should
 * not be shown and no credentials.
 */
final class BackupResult
{
    /**
     * @param array<string, mixed> $manifest
     * @param list<string>         $log
     */
    public function __construct(
        public readonly array $manifest,
        public readonly array $log,
        public readonly int $prunedCount = 0,
    ) {
    }

    public function id(): string
    {
        return (string) ($this->manifest['id'] ?? '');
    }
}
