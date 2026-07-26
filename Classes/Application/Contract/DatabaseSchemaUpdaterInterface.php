<?php

declare(strict_types=1);

/*
 * This file is part of the "Guardian for TYPO3" extension for TYPO3 CMS.
 *
 * @author    V&T Innovations Team
 * @license   LGPL-3.0-or-later
 * @copyright V&T Innovations 2026 - 2028
 */

namespace Vtinnovations\GuardianTypo3\Application\Contract;

/**
 * Port for applying database schema changes after an update.
 *
 * Replaces the Contao original's `contao:migrate`. The TYPO3 equivalent is the
 * install-tool schema migration / `database:updateschema` combined with
 * extension upgrade wizards. Applying schema changes is destructive and belongs
 * to a later phase; only the seam is defined now.
 */
interface DatabaseSchemaUpdaterInterface
{
    /**
     * Returns a human-readable list of pending schema changes without applying
     * them (the safe, read-only "dry run").
     *
     * @return list<string>
     * @throws \Vtinnovations\GuardianTypo3\Domain\Exception\GuardianException when unavailable.
     */
    public function pendingChanges(): array;

    /**
     * Applies safe (additive) schema changes.
     *
     * @throws \Vtinnovations\GuardianTypo3\Domain\Exception\GuardianException when unavailable or on failure.
     */
    public function applySafeChanges(): void;
}
