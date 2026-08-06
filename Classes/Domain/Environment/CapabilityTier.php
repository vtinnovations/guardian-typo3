<?php

declare(strict_types=1);

/*
 * This file is part of the "Guardian for TYPO3" extension for TYPO3 CMS.
 *
 * @author    V&T Innovations Team
 * @license   LGPL-3.0-or-later
 * @copyright V&T Innovations 2026 - 2028
 */

namespace Vtinnovations\GuardianTypo3\Domain\Environment;

/**
 * Capability tiers as reported by the service record's "package" field.
 *
 * None — nothing beyond the Dashboard, the Settings section and the licence
 *        screen itself; every protected operation behaves as if the extension
 *        were not licensed at all.
 * Free — additionally unlocks manual backup.
 * Pro  — additionally unlocks updates, restore, scheduling, the recovery panel
 *        and notifications.
 *
 * Both tiers still require an activated, signed vendor record. "Free" is a price,
 * not an absence of a licence: nothing here is reachable without one.
 */
enum CapabilityTier: string
{
    case None = 'none';
    case Free = 'free';
    case Pro = 'pro';

    /**
     * The package values this product is sold as.
     *
     * The list is closed on purpose. An unrecognised value is not quietly read
     * as the smallest tier — the vendor may use it for a different product, and
     * guessing what it unlocks here would be inventing an entitlement. It maps
     * to None, and the record rules refuse the document outright.
     */
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
