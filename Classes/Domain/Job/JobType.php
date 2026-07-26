<?php

declare(strict_types=1);

/*
 * This file is part of the "Guardian for TYPO3" extension for TYPO3 CMS.
 *
 * @author    V&T Innovations Team
 * @license   LGPL-3.0-or-later
 * @copyright V&T Innovations 2026 - 2028
 */

namespace Vtinnovations\GuardianTypo3\Domain\Job;

/**
 * The kind of work a job performs. Restore and update jobs are destructive and
 * gated to later phases; the type is modelled now so the job value object and
 * its persistence format are stable from the start.
 */
enum JobType: string
{
    case DryRun = 'dry_run';
    case Update = 'update';
    case Restore = 'restore';

    public static function fromString(string $value): self
    {
        return self::tryFrom($value) ?? self::DryRun;
    }

    public function isDestructive(): bool
    {
        return $this !== self::DryRun;
    }
}
