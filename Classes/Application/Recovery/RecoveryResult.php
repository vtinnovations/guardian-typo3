<?php

declare(strict_types=1);

/*
 * This file is part of the "Guardian for TYPO3" extension for TYPO3 CMS.
 *
 * @author    V&T Innovations Team
 * @license   LGPL-3.0-or-later
 * @copyright V&T Innovations 2026 - 2028
 */

namespace Vtinnovations\GuardianTypo3\Application\Recovery;

/**
 * Immutable outcome of a recovery run: the backup it restored from, the
 * components restored, the optional pre-recovery safety snapshot ID, and the log.
 */
final class RecoveryResult
{
    /**
     * @param list<string> $restoredComponents
     * @param list<string> $log
     */
    public function __construct(
        public readonly string $backupId,
        public readonly array $restoredComponents,
        public readonly ?string $safetySnapshotId,
        public readonly array $log,
        public readonly bool $rolledBack = false,
    ) {
    }
}
