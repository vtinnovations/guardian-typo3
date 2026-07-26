<?php

declare(strict_types=1);

/*
 * This file is part of the "Guardian for TYPO3" extension for TYPO3 CMS.
 *
 * @author    V&T Innovations Team
 * @license   LGPL-3.0-or-later
 * @copyright V&T Innovations 2026 - 2028
 */

namespace Vtinnovations\GuardianTypo3\Domain\Archive;

/**
 * Archive path-traversal ("zip-slip"/"tar-slip") guard.
 *
 * Extracts of tar/zip backups must never write outside the intended target
 * directory. The audited Contao RestoreManager rejected any entry with an
 * absolute path or a ".." segment before extraction; that rule is isolated
 * here as a pure, restore-independent validator so it can be unit-tested and
 * reused by every future archive consumer (backup verification, restore,
 * recovery panel) without duplicating the logic.
 *
 * Restore itself is a later, destructive phase — but the rule that decides
 * whether an archive entry is safe is pure data reasoning and belongs in the
 * domain now.
 */
final class ArchiveEntryValidator
{
    /**
     * True when the entry name is safe to extract relative to a target root.
     *
     * Rejects:
     *   - empty names
     *   - POSIX absolute paths ("/etc/passwd")
     *   - Windows drive/UNC absolute paths ("C:\\", "\\\\host")
     *   - any component equal to ".." (regardless of slash direction)
     */
    public function isSafe(string $entry): bool
    {
        $entry = trim($entry);
        if ($entry === '') {
            return false;
        }

        if (str_starts_with($entry, '/') || str_starts_with($entry, '\\')) {
            return false;
        }

        if (preg_match('#^[A-Za-z]:[\\\\/]#', $entry) === 1) {
            return false;
        }

        foreach (preg_split('#[/\\\\]+#', $entry) ?: [] as $segment) {
            if ($segment === '..') {
                return false;
            }
        }

        return true;
    }

    /**
     * Filters a list of archive entries to only the unsafe ones. An empty
     * result means the archive listing is safe to extract.
     *
     * @param iterable<string> $entries
     * @return list<string>
     */
    public function unsafeEntries(iterable $entries): array
    {
        $unsafe = [];
        foreach ($entries as $entry) {
            if (!$this->isSafe((string) $entry)) {
                $unsafe[] = (string) $entry;
            }
        }

        return $unsafe;
    }

    /**
     * @param iterable<string> $entries
     */
    public function allSafe(iterable $entries): bool
    {
        return $this->unsafeEntries($entries) === [];
    }
}
