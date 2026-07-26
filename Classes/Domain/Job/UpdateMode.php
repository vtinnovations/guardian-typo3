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
 * Composer update strategies. "Major" was already disabled in the audited
 * Contao product and is intentionally omitted here until the update pipeline
 * (a later phase) is built and reviewed.
 */
enum UpdateMode: string
{
    case Full = 'full';
    case Patch = 'patch';
    case Selective = 'selective';

    public static function fromString(string $value): self
    {
        return self::tryFrom($value) ?? self::Full;
    }
}
