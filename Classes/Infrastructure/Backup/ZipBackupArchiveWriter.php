<?php

declare(strict_types=1);

/*
 * This file is part of the "Guardian for TYPO3" extension for TYPO3 CMS.
 *
 * @author    V&T Innovations Team
 * @license   LGPL-3.0-or-later
 * @copyright V&T Innovations 2026 - 2028
 */

namespace Vtinnovations\GuardianTypo3\Infrastructure\Backup;

use Vtinnovations\GuardianTypo3\Domain\Archive\ArchiveEntryValidator;
use Vtinnovations\GuardianTypo3\Domain\Exception\GuardianException;

/**
 * Streaming backup archive writer built on the PHP-native {@see \ZipArchive}.
 *
 * ZipArchive is chosen over shelling out to `tar` for portability and security:
 * it needs no external binary and no shell, so there is zero command-injection
 * surface. It streams each file from disk on close() rather than loading whole
 * directory trees into memory, which is what lets a large `fileadmin/` be
 * archived safely. It was also the portable fallback in the audited Contao
 * BackupManager, so the choice is consistent with the original.
 *
 * Every entry name is validated by {@see ArchiveEntryValidator} (no absolute
 * paths, no "..", forward slashes only) so the archive can never contain a
 * traversal or absolute-path entry. Finalisation is atomic: the caller writes to
 * a temp path and renames only after success (see BackupService).
 */
final class ZipBackupArchiveWriter
{
    private ?\ZipArchive $zip = null;

    public function __construct(
        private readonly ArchiveEntryValidator $entryValidator,
    ) {
    }

    public static function isSupported(): bool
    {
        return class_exists(\ZipArchive::class);
    }

    public function open(string $targetPath): void
    {
        if (!self::isSupported()) {
            throw new GuardianException('Backups require the PHP "zip" extension (ZipArchive), which is not available.');
        }
        $zip = new \ZipArchive();
        $opened = $zip->open($targetPath, \ZipArchive::CREATE | \ZipArchive::OVERWRITE);
        if ($opened !== true) {
            throw new GuardianException('Could not create the backup archive (ZipArchive error ' . $opened . ').');
        }
        $this->zip = $zip;
    }

    public function addFile(string $absolutePath, string $entryName): void
    {
        $this->assertOpen();
        $entryName = $this->normaliseEntryName($entryName);
        if (!$this->zip->addFile($absolutePath, $entryName)) {
            throw new GuardianException('Could not add a file to the backup archive.');
        }
    }

    public function addString(string $entryName, string $content): void
    {
        $this->assertOpen();
        $entryName = $this->normaliseEntryName($entryName);
        if (!$this->zip->addFromString($entryName, $content)) {
            throw new GuardianException('Could not add generated content to the backup archive.');
        }
    }

    public function close(): void
    {
        $this->assertOpen();
        if (!$this->zip->close()) {
            $this->zip = null;
            throw new GuardianException('Could not finalise the backup archive.');
        }
        $this->zip = null;
    }

    /**
     * Best-effort cleanup used on failure paths — never throws.
     */
    public function abort(): void
    {
        if ($this->zip !== null) {
            @$this->zip->close();
            $this->zip = null;
        }
    }

    private function normaliseEntryName(string $entryName): string
    {
        // Force forward slashes and strip any leading slash; then verify safety.
        $normalised = ltrim(str_replace('\\', '/', $entryName), '/');
        if (!$this->entryValidator->isSafe($normalised)) {
            throw new GuardianException('Refusing unsafe archive entry name: ' . $entryName);
        }

        return $normalised;
    }

    private function assertOpen(): void
    {
        if ($this->zip === null) {
            throw new GuardianException('The backup archive is not open.');
        }
    }
}
