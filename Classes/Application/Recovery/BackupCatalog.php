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

use Vtinnovations\GuardianTypo3\Domain\Exception\GuardianException;
use Vtinnovations\GuardianTypo3\Infrastructure\Backup\BackupStorage;

/**
 * The authoritative index of recoverable backups. Recovery operates ONLY on
 * backups created and indexed by Guardian — never on arbitrary paths or uploaded
 * archives. A backup ID is resolved through this catalog and fully verified
 * before it may be recovered: existence, completed status, a matching checksum,
 * and that the archive really sits inside var/guardian/backups.
 *
 * Malformed, incomplete, temporary or tampered backups are rejected here, so no
 * caller ever operates on one.
 */
final class BackupCatalog
{
    public function __construct(
        private readonly BackupStorage $storage,
    ) {
    }

    /**
     * Completed, recoverable backups newest-first (light — no checksum hashing).
     *
     * @return list<array<string, mixed>>
     */
    public function recoverableList(): array
    {
        $list = [];
        foreach ($this->storage->list() as $manifest) {
            if (($manifest['status'] ?? '') === 'completed' && \is_file($this->archivePathFor((string) ($manifest['id'] ?? '')))) {
                $manifest['checksum_present'] = ($manifest['checksum'] ?? '') !== '';
                $list[] = $manifest;
            }
        }

        return $list;
    }

    public function find(string $id): ?BackupManifest
    {
        if (!$this->storage->isValidId($id)) {
            return null;
        }
        $manifest = $this->storage->readManifest($id);

        return $manifest === null ? null : new BackupManifest($manifest);
    }

    /**
     * Fully verifies a backup and returns its manifest, or throws with a precise
     * reason. This is the single gate every recovery path must pass through.
     *
     * @throws GuardianException
     */
    public function assertRecoverable(string $id): BackupManifest
    {
        if (!$this->storage->isValidId($id)) {
            throw new GuardianException('Invalid backup identifier.');
        }
        $manifest = $this->find($id);
        if ($manifest === null) {
            throw new GuardianException('Backup manifest not found or unreadable.');
        }
        if (!$manifest->isCompleted()) {
            throw new GuardianException('Backup is not marked as completed and cannot be recovered.');
        }

        $archive = $this->storage->archivePath($id); // also re-validates the ID
        if (!\is_file($archive)) {
            throw new GuardianException('Backup archive is missing.');
        }
        // Containment: the archive must live inside var/guardian/backups.
        if (!$this->storage->contains($archive)) {
            throw new GuardianException('Backup archive is outside the Guardian backups directory.');
        }
        if ($manifest->checksum() === '') {
            throw new GuardianException('Backup has no checksum and cannot be verified.');
        }
        if (!$this->checksumMatches($archive, $manifest)) {
            throw new GuardianException('Backup checksum does not match — the archive may be corrupt or tampered with.');
        }

        return $manifest;
    }

    public function checksumMatches(string $archive, BackupManifest $manifest): bool
    {
        $algo = $manifest->checksumAlgo();
        if (!\in_array($algo, hash_algos(), true)) {
            return false;
        }
        $actual = @hash_file($algo, $archive);

        return $actual !== false && hash_equals($manifest->checksum(), $actual);
    }

    public function archivePathFor(string $id): string
    {
        return $this->storage->isValidId($id) ? $this->storage->archivePath($id) : '';
    }
}
