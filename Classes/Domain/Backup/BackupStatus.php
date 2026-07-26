<?php

declare(strict_types=1);

/*
 * This file is part of the "Guardian for TYPO3" extension for TYPO3 CMS.
 *
 * @author    V&T Innovations Team
 * @license   LGPL-3.0-or-later
 * @copyright V&T Innovations 2026 - 2028
 */

namespace Vtinnovations\GuardianTypo3\Domain\Backup;

/**
 * Lifecycle status of a backup. A backup only ever becomes {@see self::Completed}
 * after its archive has been finalised atomically and its checksum computed; a
 * failure never leaves a completed-looking archive.
 */
enum BackupStatus: string
{
    case Running = 'running';
    case Completed = 'completed';
    case Failed = 'failed';

    public static function fromString(string $value): self
    {
        return self::tryFrom($value) ?? self::Failed;
    }
}
