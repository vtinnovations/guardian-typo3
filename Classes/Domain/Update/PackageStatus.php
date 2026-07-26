<?php

declare(strict_types=1);

/*
 * This file is part of the "Guardian for TYPO3" extension for TYPO3 CMS.
 *
 * @author    V&T Innovations Team
 * @license   LGPL-3.0-or-later
 * @copyright V&T Innovations 2026 - 2028
 */

namespace Vtinnovations\GuardianTypo3\Domain\Update;

/**
 * Language-neutral machine status for an installed package's update situation.
 *
 * These values are the authoritative codes carried in every API payload; the
 * backend and JavaScript translate them to localized labels. Never send a
 * localized label where a code is expected.
 *
 * The classification is a generic semantic-version comparison (major → minor →
 * patch), so it is not tied to any TYPO3- or Contao-specific versioning scheme.
 */
enum PackageStatus: string
{
    case Current = 'current';
    case PatchAvailable = 'patch_available';
    case MinorAvailable = 'minor_available';
    case MajorAvailable = 'major_available';
    case Abandoned = 'abandoned';
    case Unknown = 'unknown';
    case Error = 'error';

    /**
     * Classifies a package from its current and latest versions.
     *
     * @param bool $abandoned when true the package is always {@see self::Abandoned}
     */
    public static function classify(string $current, string $latest, bool $abandoned = false): self
    {
        if ($abandoned) {
            return self::Abandoned;
        }
        $current = self::parse($current);
        $latest = self::parse($latest);
        if ($current === null || $latest === null) {
            return self::Unknown;
        }
        if ($latest[0] > $current[0]) {
            return self::MajorAvailable;
        }
        if ($latest[0] < $current[0]) {
            return self::Current; // installed is ahead — nothing to offer
        }
        if ($latest[1] > $current[1]) {
            return self::MinorAvailable;
        }
        if ($latest[1] < $current[1]) {
            return self::Current;
        }
        if ($latest[2] > $current[2]) {
            return self::PatchAvailable;
        }

        return self::Current;
    }

    public function hasUpdate(): bool
    {
        return match ($this) {
            self::PatchAvailable, self::MinorAvailable, self::MajorAvailable => true,
            default => false,
        };
    }

    /**
     * Parses "v5.3.45", "5.3", "5.3.45-beta1" → [major, minor, patch].
     *
     * @return array{0:int,1:int,2:int}|null
     */
    private static function parse(string $version): ?array
    {
        $version = ltrim(trim($version), 'vV');
        if ($version === '') {
            return null;
        }
        if (preg_match('/^(\d+)(?:\.(\d+))?(?:\.(\d+))?/', $version, $m) !== 1) {
            return null;
        }

        return [(int) $m[1], (int) ($m[2] ?? 0), (int) ($m[3] ?? 0)];
    }
}
