<?php

declare(strict_types=1);

/*
 * This file is part of the "Guardian for TYPO3" extension for TYPO3 CMS.
 *
 * @author    V&T Innovations Team
 * @license   LGPL-3.0-or-later
 * @copyright V&T Innovations 2026 - 2028
 */

namespace Vtinnovations\GuardianTypo3\Application\Backup;

/**
 * Pure retention calculation, ported from the audited Contao
 * ScheduledBackupRunner::applyRetention. Given the existing backups of one type,
 * newest-first, it decides which backup IDs to prune so at most $keep remain.
 * It performs no I/O and is fully unit-testable.
 */
final class RetentionPolicy
{
    /**
     * @param list<array{id: string, type: string}> $backups all known backups (any order)
     * @param 'manual'|'mini'|'full'                 $type    the type to prune
     * @return list<string> backup IDs to delete (oldest beyond the keep limit)
     */
    public function idsToPrune(array $backups, string $type, int $keep): array
    {
        $keep = max(1, $keep);

        $ofType = array_values(array_filter(
            $backups,
            static fn (array $b): bool => (string) ($b['type'] ?? '') === $type
        ));

        // Newest first by ID (IDs are timestamp-prefixed, so lexical == chronological).
        usort($ofType, static fn (array $a, array $b): int => strcmp((string) $b['id'], (string) $a['id']));

        if (\count($ofType) <= $keep) {
            return [];
        }

        return array_map(
            static fn (array $b): string => (string) $b['id'],
            \array_slice($ofType, $keep)
        );
    }
}
