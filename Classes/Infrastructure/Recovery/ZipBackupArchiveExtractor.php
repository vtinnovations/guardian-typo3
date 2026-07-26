<?php

declare(strict_types=1);

/*
 * This file is part of the "Guardian for TYPO3" extension for TYPO3 CMS.
 *
 * @author    V&T Innovations Team
 * @license   LGPL-3.0-or-later
 * @copyright V&T Innovations 2026 - 2028
 */

namespace Vtinnovations\GuardianTypo3\Infrastructure\Recovery;

use Vtinnovations\GuardianTypo3\Domain\Archive\ArchiveEntryValidator;
use Vtinnovations\GuardianTypo3\Domain\Exception\GuardianException;

/**
 * Reads and safely extracts a Guardian backup ZIP.
 *
 * Ports the zip-slip protection from the audited Contao RestoreManager: before
 * anything is extracted, EVERY entry name is validated (no absolute paths, no
 * "..", forward slashes only). Extraction is done with {@see \ZipArchive::extractTo}
 * restricted to a known list of validated entries, and single entries (the
 * database dump) are streamed to disk rather than loaded into memory.
 */
final class ZipBackupArchiveExtractor
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

    public function open(string $archivePath): void
    {
        if (!self::isSupported()) {
            throw new GuardianException('Recovery requires the PHP "zip" extension (ZipArchive), which is not available.');
        }
        $zip = new \ZipArchive();
        if ($zip->open($archivePath) !== true) {
            throw new GuardianException('Could not open the backup archive.');
        }
        $this->zip = $zip;
    }

    public function close(): void
    {
        if ($this->zip !== null) {
            @$this->zip->close();
            $this->zip = null;
        }
    }

    /**
     * @return list<string>
     */
    public function entryNames(): array
    {
        $zip = $this->requireZip();
        $names = [];
        for ($i = 0; $i < $zip->numFiles; $i++) {
            $name = $zip->getNameIndex($i);
            if ($name !== false) {
                $names[] = $name;
            }
        }

        return $names;
    }

    /**
     * Rejects the whole archive if ANY entry is unsafe (traversal/absolute).
     *
     * @throws GuardianException
     */
    public function assertSafeEntries(): void
    {
        $unsafe = $this->entryValidator->unsafeEntries($this->entryNames());
        if ($unsafe !== []) {
            throw new GuardianException('Refusing to extract: unsafe archive entry "' . $unsafe[0] . '".');
        }
    }

    public function hasEntry(string $name): bool
    {
        return $this->requireZip()->locateName($name) !== false;
    }

    /**
     * Entry names that make up a component: the exact file, or everything under
     * "<prefix>/".
     *
     * @return list<string>
     */
    public function entriesUnderPrefix(string $prefix): array
    {
        $prefix = trim(str_replace('\\', '/', $prefix), '/');
        $result = [];
        foreach ($this->entryNames() as $name) {
            if ($name === $prefix || str_starts_with($name, $prefix . '/')) {
                $result[] = $name;
            }
        }

        return $result;
    }

    /**
     * Extracts a validated subset of entries to a target directory.
     *
     * @param list<string> $entries
     * @throws GuardianException
     */
    public function extractEntries(array $entries, string $targetDir): void
    {
        if ($entries === []) {
            return;
        }
        foreach ($entries as $entry) {
            if (!$this->entryValidator->isSafe($entry)) {
                throw new GuardianException('Refusing to extract unsafe entry: ' . $entry);
            }
        }
        if (!$this->requireZip()->extractTo($targetDir, $entries)) {
            throw new GuardianException('Extraction failed for ' . \count($entries) . ' entrie(s).');
        }
    }

    /**
     * Streams a single entry to a file on disk (used for the database dump).
     *
     * @throws GuardianException
     */
    public function extractEntryToFile(string $entryName, string $targetFile): int
    {
        if (!$this->entryValidator->isSafe($entryName)) {
            throw new GuardianException('Refusing to extract unsafe entry: ' . $entryName);
        }
        $in = $this->requireZip()->getStream($entryName);
        if ($in === false) {
            throw new GuardianException('Could not read "' . $entryName . '" from the archive.');
        }
        $out = @fopen($targetFile, 'wb');
        if ($out === false) {
            fclose($in);
            throw new GuardianException('Could not open the temporary dump file for writing.');
        }
        $bytes = 0;
        while (!feof($in)) {
            $chunk = fread($in, 262144);
            if ($chunk === false) {
                break;
            }
            fwrite($out, $chunk);
            $bytes += \strlen($chunk);
        }
        fclose($in);
        fclose($out);

        return $bytes;
    }

    private function requireZip(): \ZipArchive
    {
        if ($this->zip === null) {
            throw new GuardianException('The backup archive is not open.');
        }

        return $this->zip;
    }
}
