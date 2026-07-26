<?php

declare(strict_types=1);

/*
 * This file is part of the "Guardian for TYPO3" extension for TYPO3 CMS.
 *
 * @author    V&T Innovations Team
 * @license   LGPL-3.0-or-later
 * @copyright V&T Innovations 2026 - 2028
 */

namespace Vtinnovations\GuardianTypo3\Domain\Recovery;

/**
 * How Guardian re-establishes the `vendor/` directory during a recovery.
 *
 * A production incident proved that copying an archived `vendor/` over the live
 * one is fundamentally unsafe (foreign/incomplete/corrupt trees, macOS metadata,
 * broken symlinks, and — worst — the running PHP process losing the classes it is
 * mid-execution of). So the SAFE DEFAULT is never "copy the archived vendor":
 *
 *   - Rebuild  (default): restore composer.json + composer.lock, then rebuild
 *              vendor/ with `composer install` in an isolated staging directory
 *              and atomically switch it into place. Deterministic and portable.
 *   - Archived (advanced, high risk): only allowed when EVERY strict compatibility
 *              check passes; still staged + validated + atomically switched.
 *   - Skip:    do not touch vendor/ at all (composer files only).
 */
enum VendorRestoreStrategy: string
{
    case Rebuild = 'rebuild';
    case Archived = 'archived';
    case Skip = 'skip';

    public static function fromString(?string $value): self
    {
        return match ($value) {
            'archived' => self::Archived,
            'skip' => self::Skip,
            default => self::Rebuild,
        };
    }

    /** The archived strategy is the only one that touches the vendor archive. */
    public function usesArchive(): bool
    {
        return $this === self::Archived;
    }

    public function touchesVendor(): bool
    {
        return $this !== self::Skip;
    }
}
