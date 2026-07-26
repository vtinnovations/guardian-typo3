<?php

declare(strict_types=1);

/*
 * This file is part of the "Guardian for TYPO3" extension for TYPO3 CMS.
 *
 * @author    V&T Innovations Team
 * @license   LGPL-3.0-or-later
 * @copyright V&T Innovations 2026 - 2028
 */

namespace Vtinnovations\GuardianTypo3\Domain\License;

/**
 * Entitlement tiers as reported by the V&T license server "package" field.
 *
 * None    — no valid license: only Dashboard + Settings are usable.
 * Free    — unlocks manual backup.
 * Pro     — unlocks updates, restore, scheduling, recovery panel, notifications.
 */
enum LicenseTier: string
{
    case None = 'none';
    case Free = 'free';
    case Pro = 'pro';

    public static function fromPackage(string $package): self
    {
        return match (strtolower(trim($package))) {
            'pro' => self::Pro,
            'free' => self::Free,
            default => self::None,
        };
    }

    public function isAtLeast(self $required): bool
    {
        return $this->rank() >= $required->rank();
    }

    private function rank(): int
    {
        return match ($this) {
            self::None => 0,
            self::Free => 1,
            self::Pro => 2,
        };
    }
}
