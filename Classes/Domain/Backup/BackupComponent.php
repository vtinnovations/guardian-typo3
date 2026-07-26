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
 * The selectable contents of a backup, ported from the audited Contao
 * BackupManager component set and mapped to the correct TYPO3 project layout.
 *
 * Baseline components (composer.json, composer.lock, database) are always
 * included by server policy; the remaining components are only ever included
 * when the administrator explicitly selects them (never inferred from a browser
 * value alone). Each case's string value is the wire key used in the request
 * contract and in the manifest.
 */
enum BackupComponent: string
{
    case ComposerJson = 'composerJson';
    case ComposerLock = 'composerLock';
    case Database = 'database';
    case Vendor = 'vendor';
    case Configuration = 'configuration';
    case Packages = 'packages';
    case Templates = 'templates';
    case Fileadmin = 'fileadmin';
    case PublicAssets = 'publicAssets';

    /**
     * Components always included in a backup (baseline), regardless of payload.
     *
     * @return list<self>
     */
    public static function baseline(): array
    {
        return [self::ComposerJson, self::ComposerLock, self::Database];
    }

    /**
     * Components that require explicit opt-in by the administrator.
     *
     * @return list<self>
     */
    public static function optional(): array
    {
        return [
            self::Vendor,
            self::Configuration,
            self::Packages,
            self::Templates,
            self::Fileadmin,
            self::PublicAssets,
        ];
    }

    public function isBaseline(): bool
    {
        return \in_array($this, self::baseline(), true);
    }

    /**
     * Whether this component is a single project file (vs. a directory or the
     * database pseudo-component).
     */
    public function isSingleFile(): bool
    {
        return $this === self::ComposerJson || $this === self::ComposerLock;
    }

    public function isDatabase(): bool
    {
        return $this === self::Database;
    }
}
