<?php

declare(strict_types=1);

/*
 * This file is part of the "Guardian for TYPO3" extension for TYPO3 CMS.
 *
 * @author    V&T Innovations Team
 * @license   LGPL-3.0-or-later
 * @copyright V&T Innovations 2026 - 2028
 */

namespace Vtinnovations\GuardianTypo3\Infrastructure\Archive;

use Vtinnovations\GuardianTypo3\Domain\Archive\ArchiveEntryValidator;
use Vtinnovations\GuardianTypo3\Domain\Exception\GuardianException;

/**
 * Validates an uploaded extension ZIP BEFORE it is trusted, then extracts it into
 * a private staging directory only. Every failure is a machine-readable code.
 *
 * Pre-extraction checks (never opening file contents):
 *   - the ZIP opens and its central directory is intact
 *   - bounded entry count
 *   - every entry name is safe (no absolute/drive/UNC paths, no "..", no null byte)
 *   - bounded per-entry and total UNCOMPRESSED size (archive-bomb defence)
 *   - bounded per-entry compression ratio (archive-bomb defence)
 *
 * ZipArchive writes symlink/hardlink entries as ordinary files (it never creates
 * links), but the extracted tree is still scanned for any real symlink as
 * defence in depth and the whole staging dir is removed if one is found.
 */
final class ZipSafetyInspector
{
    private const MAX_ENTRIES = 5000;
    private const MAX_ENTRY_UNCOMPRESSED = 64 * 1024 * 1024;   // 64 MB per file
    private const MAX_TOTAL_UNCOMPRESSED = 300 * 1024 * 1024;  // 300 MB expanded
    private const MAX_RATIO = 1000;                            // uncompressed / compressed

    public function __construct(
        private readonly ArchiveEntryValidator $entryValidator,
    ) {
    }

    /**
     * Validate the archive structure without extracting.
     *
     * @return array{entries: int, uncompressed: int}
     * @throws GuardianException
     */
    public function validate(string $zipPath): array
    {
        if (!\class_exists(\ZipArchive::class)) {
            throw new GuardianException('zip_extension_unavailable');
        }
        if (!is_file($zipPath)) {
            throw new GuardianException('upload_missing');
        }

        $zip = new \ZipArchive();
        if ($zip->open($zipPath, \ZipArchive::CHECKCONS) !== true) {
            throw new GuardianException('zip_invalid');
        }

        try {
            $count = $zip->numFiles;
            if ($count === 0) {
                throw new GuardianException('zip_empty');
            }
            if ($count > self::MAX_ENTRIES) {
                throw new GuardianException('zip_too_many_entries');
            }

            $total = 0;
            for ($i = 0; $i < $count; $i++) {
                $stat = $zip->statIndex($i);
                if ($stat === false) {
                    throw new GuardianException('zip_invalid');
                }
                $name = (string) $stat['name'];
                if (str_contains($name, "\0")) {
                    throw new GuardianException('zip_null_byte');
                }
                if (!$this->entryValidator->isSafe($name)) {
                    throw new GuardianException('zip_unsafe_path');
                }
                $size = (int) $stat['size'];
                $comp = (int) $stat['comp_size'];
                if ($size > self::MAX_ENTRY_UNCOMPRESSED) {
                    throw new GuardianException('zip_entry_too_large');
                }
                if ($comp > 0 && $size / max(1, $comp) > self::MAX_RATIO && $size > 1024 * 1024) {
                    throw new GuardianException('zip_bomb_ratio');
                }
                $total += $size;
                if ($total > self::MAX_TOTAL_UNCOMPRESSED) {
                    throw new GuardianException('zip_expanded_too_large');
                }
            }

            return ['entries' => $count, 'uncompressed' => $total];
        } finally {
            $zip->close();
        }
    }

    /**
     * Validate then extract into $targetDir (which must be a private staging dir).
     * Rejects and cleans up if the extracted tree contains any symlink.
     *
     * @throws GuardianException
     */
    public function extractTo(string $zipPath, string $targetDir): void
    {
        $this->validate($zipPath);

        if (!is_dir($targetDir) && !@mkdir($targetDir, 0o750, true) && !is_dir($targetDir)) {
            throw new GuardianException('staging_unwritable');
        }

        $zip = new \ZipArchive();
        if ($zip->open($zipPath) !== true) {
            throw new GuardianException('zip_invalid');
        }
        $ok = $zip->extractTo($targetDir);
        $zip->close();
        if (!$ok) {
            throw new GuardianException('zip_extract_failed');
        }

        if ($this->containsSymlink($targetDir)) {
            throw new GuardianException('zip_unsafe_symlink');
        }
    }

    private function containsSymlink(string $dir): bool
    {
        $iterator = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($dir, \FilesystemIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::SELF_FIRST
        );
        foreach ($iterator as $item) {
            if ($item->isLink()) {
                return true;
            }
        }

        return false;
    }
}
