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

use Vtinnovations\GuardianTypo3\Application\Contract\WorkingDirectoryProviderInterface;
use Vtinnovations\GuardianTypo3\Domain\Exception\GuardianException;
use Vtinnovations\GuardianTypo3\Domain\Filesystem\PathNormalizer;

/**
 * Owns the on-disk layout of backups under <TYPO3 project>/var/guardian/backups.
 *
 * Every backup consists of three sibling files keyed by a strict, server-issued
 * ID: the archive (`<id>.zip`), a manifest sidecar (`<id>.json`, so listing does
 * not need to open the archive) and a log (`<id>.log`). IDs coming from HTTP are
 * never trusted — they are validated against a strict pattern before any path is
 * built, so a request can never address a file outside the backups directory.
 *
 * Backups live OUTSIDE the web root (under var/), never under public/,
 * fileadmin/, Resources/Public or the extension directory.
 */
final class BackupStorage
{
    private const ID_PATTERN = '/^\d{4}-\d{2}-\d{2}_\d{2}-\d{2}-\d{2}-[a-f0-9]{8}$/';
    private const DIR_MODE = 0o750;
    private const FILE_MODE = 0o640;

    public function __construct(
        private readonly WorkingDirectoryProviderInterface $workingDirectory,
        private readonly PathNormalizer $pathNormalizer,
    ) {
    }

    public function baseDir(): string
    {
        return $this->workingDirectory->resolve('backups');
    }

    /**
     * Creates the backups directory (and its parents) with restrictive
     * permissions if needed. Returns the absolute base directory.
     *
     * @throws GuardianException when the directory cannot be created/written
     */
    public function ensureBaseDir(): string
    {
        $dir = $this->baseDir();
        if (!is_dir($dir) && !@mkdir($dir, self::DIR_MODE, true) && !is_dir($dir)) {
            throw new GuardianException('Could not create the Guardian backups directory.');
        }
        if (!is_writable($dir)) {
            throw new GuardianException('The Guardian backups directory is not writable.');
        }

        return $dir;
    }

    public function generateId(\DateTimeImmutable $now): string
    {
        return $now->format('Y-m-d_H-i-s') . '-' . bin2hex(random_bytes(4));
    }

    public function isValidId(string $id): bool
    {
        return preg_match(self::ID_PATTERN, $id) === 1;
    }

    public function assertValidId(string $id): void
    {
        if (!$this->isValidId($id)) {
            throw new GuardianException('Invalid backup identifier.');
        }
    }

    public function archivePath(string $id): string
    {
        $this->assertValidId($id);

        return $this->baseDir() . '/' . $id . '.zip';
    }

    public function tempArchivePath(string $id): string
    {
        $this->assertValidId($id);

        return $this->baseDir() . '/' . $id . '.zip.tmp';
    }

    public function tempSqlPath(string $id): string
    {
        $this->assertValidId($id);

        return $this->baseDir() . '/' . $id . '.sql.tmp';
    }

    public function manifestPath(string $id): string
    {
        $this->assertValidId($id);

        return $this->baseDir() . '/' . $id . '.json';
    }

    public function logPath(string $id): string
    {
        $this->assertValidId($id);

        return $this->baseDir() . '/' . $id . '.log';
    }

    public function fileMode(): int
    {
        return self::FILE_MODE;
    }

    /**
     * Whether $absolutePath is inside the backups directory — used by the file
     * collector to make sure a backup can never recursively include itself or
     * any other backup.
     */
    public function contains(string $absolutePath): bool
    {
        return $this->pathNormalizer->isContained($this->baseDir(), $absolutePath);
    }

    /**
     * @param array<string, mixed> $manifest
     */
    public function writeManifest(string $id, array $manifest): void
    {
        $file = $this->manifestPath($id);
        $json = json_encode($manifest, \JSON_PRETTY_PRINT | \JSON_UNESCAPED_SLASHES | \JSON_UNESCAPED_UNICODE);
        if ($json === false) {
            throw new GuardianException('Could not encode backup manifest.');
        }
        if (@file_put_contents($file, $json, \LOCK_EX) === false) {
            throw new GuardianException('Could not write backup manifest.');
        }
        @chmod($file, self::FILE_MODE);
    }

    /**
     * @return array<string, mixed>|null
     */
    public function readManifest(string $id): ?array
    {
        if (!$this->isValidId($id)) {
            return null;
        }
        $file = $this->manifestPath($id);
        if (!is_file($file)) {
            return null;
        }
        $decoded = json_decode((string) @file_get_contents($file), true);

        return \is_array($decoded) ? $decoded : null;
    }

    /**
     * Lists all completed/known backups newest-first, from the manifest sidecars.
     *
     * @return list<array<string, mixed>>
     */
    public function list(): array
    {
        $dir = $this->baseDir();
        if (!is_dir($dir)) {
            return [];
        }
        $files = glob($dir . '/*.json');
        if ($files === false) {
            return [];
        }

        $backups = [];
        foreach ($files as $file) {
            $id = basename($file, '.json');
            if (!$this->isValidId($id)) {
                continue;
            }
            $manifest = $this->readManifest($id);
            if ($manifest !== null) {
                $backups[] = $manifest;
            }
        }

        usort($backups, static fn (array $a, array $b): int => strcmp((string) ($b['id'] ?? ''), (string) ($a['id'] ?? '')));

        return $backups;
    }

    /**
     * Deletes all three files of a backup. Returns true if the archive is gone.
     */
    public function delete(string $id): bool
    {
        $this->assertValidId($id);
        foreach ([$this->archivePath($id), $this->manifestPath($id), $this->logPath($id)] as $file) {
            if (is_file($file)) {
                @unlink($file);
            }
        }

        return !is_file($this->archivePath($id));
    }
}
