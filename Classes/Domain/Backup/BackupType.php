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
 * The kind of backup being produced. "Manual" is an administrator-initiated
 * backup; "Mini" and "Full" are the two scheduled-backup profiles ported from
 * the audited Contao ScheduleConfig (mini = database + composer files only,
 * full = database + selected directories).
 */
enum BackupType: string
{
    case Manual = 'manual';
    case Mini = 'mini';
    case Full = 'full';
    case PreUpdate = 'pre-update';

    public static function fromString(string $value): self
    {
        return self::tryFrom($value) ?? self::Manual;
    }
}
