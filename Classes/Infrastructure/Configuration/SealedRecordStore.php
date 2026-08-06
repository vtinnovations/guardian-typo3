<?php

declare(strict_types=1);

/*
 * This file is part of the "Guardian for TYPO3" extension for TYPO3 CMS.
 *
 * @author    V&T Innovations Team
 * @license   LGPL-3.0-or-later
 * @copyright V&T Innovations 2026 - 2028
 */

namespace Vtinnovations\GuardianTypo3\Infrastructure\Configuration;

use Vtinnovations\GuardianTypo3\Application\Contract\LockFactoryInterface;
use Vtinnovations\GuardianTypo3\Application\Contract\ServiceRecordStoreInterface;
use Vtinnovations\GuardianTypo3\Application\Contract\WorkingDirectoryProviderInterface;
use Vtinnovations\GuardianTypo3\Domain\Configuration\StoredRecord;
use Vtinnovations\GuardianTypo3\Domain\Environment\HostIdentity;
use Vtinnovations\GuardianTypo3\Domain\Exception\GuardianException;
use Vtinnovations\GuardianTypo3\Infrastructure\Manifest\SealedPackage;

/**
 * Keeps the record pair in Guardian's private working directory and swaps it
 * atomically.
 *
 * The directory sits under the project's var/ path, outside the document root,
 * and no path ever comes from a request: the file names are fixed constants and
 * the directory is resolved by the working-directory provider, which refuses
 * anything that would escape it.
 *
 * A replacement is a small transaction. Both files are staged beside their
 * targets on the same filesystem, flushed, read back and verified while still
 * staged; only then is the previous pair backed up and the new pair renamed into
 * place. The activated pair is verified once more, and if that final check fails
 * the backup is restored, so an interrupted or partially written swap can never
 * leave a document without a matching envelope.
 */
final class SealedRecordStore implements ServiceRecordStoreInterface
{
    private const DOCUMENT = 'license.json';
    private const ENVELOPE = 'license.seal.json';
    private const INSTALLATION = 'installation.json';
    private const LOCK = 'record-state';

    public function __construct(
        private readonly WorkingDirectoryProviderInterface $workingDirectory,
        private readonly SealedPackage $package,
        private readonly LockFactoryInterface $lockFactory,
    ) {
    }

    public function read(int $now): StoredRecord
    {
        $bytes = $this->readFile(self::DOCUMENT);
        $envelopeBytes = $this->readFile(self::ENVELOPE);
        if ($bytes === null || $envelopeBytes === null) {
            return StoredRecord::none();
        }

        $envelope = json_decode($envelopeBytes, true);
        if (!\is_array($envelope) || array_is_list($envelope)) {
            return StoredRecord::none('envelope_unreadable');
        }

        $result = $this->package->openStored($bytes, $envelope, $now);

        return $result->trusted && $result->record !== null
            ? StoredRecord::of($result->record)
            : StoredRecord::none($result->category);
    }

    public function replace(string $documentBytes, array $envelope, int $now): void
    {
        $envelopeBytes = json_encode($envelope, \JSON_UNESCAPED_SLASHES | \JSON_UNESCAPED_UNICODE);
        if ($envelopeBytes === false) {
            throw new GuardianException('The service record could not be stored.');
        }

        // Never activate something that does not already verify. The caller has
        // checked it, and it is checked again here so this is true regardless of
        // which caller reached us.
        $candidate = $this->package->openStored($documentBytes, $envelope, $now);
        if (!$candidate->trusted) {
            throw new GuardianException('The service record could not be stored.');
        }

        $directory = $this->directory();
        $lock = $this->lockFactory->create(self::LOCK, 60);
        if (!$this->acquire($lock)) {
            throw new GuardianException('The service record is currently being updated.');
        }

        $documentPath = $this->workingDirectory->resolve(self::DOCUMENT);
        $envelopePath = $this->workingDirectory->resolve(self::ENVELOPE);
        $stagedDocument = $documentPath . '.staged';
        $stagedEnvelope = $envelopePath . '.staged';
        $backupDocument = $documentPath . '.previous';
        $backupEnvelope = $envelopePath . '.previous';
        $hadPrevious = is_file($documentPath) && is_file($envelopePath);

        try {
            // 1. Stage on the same filesystem so the activation is a rename.
            $this->writeExact($stagedDocument, $documentBytes);
            $this->writeExact($stagedEnvelope, $envelopeBytes);

            // 2. Read the staged bytes back and verify them before they matter.
            if (@file_get_contents($stagedDocument) !== $documentBytes
                || @file_get_contents($stagedEnvelope) !== $envelopeBytes
            ) {
                throw new GuardianException('The service record could not be staged.');
            }

            // 3. Keep the previous pair so a failed activation can be undone.
            if ($hadPrevious) {
                @copy($documentPath, $backupDocument);
                @copy($envelopePath, $backupEnvelope);
            }

            // 4. Activate both halves.
            if (!@rename($stagedDocument, $documentPath)) {
                throw new GuardianException('The service record could not be activated.');
            }
            if (!@rename($stagedEnvelope, $envelopePath)) {
                $this->rollback($hadPrevious, $backupDocument, $documentPath, $backupEnvelope, $envelopePath);
                throw new GuardianException('The service record could not be activated.');
            }
            @chmod($documentPath, 0o640);
            @chmod($envelopePath, 0o640);

            // 5. Verify what is actually live now, not what we intended to write.
            if (!$this->read($now)->exists()) {
                $this->rollback($hadPrevious, $backupDocument, $documentPath, $backupEnvelope, $envelopePath);
                throw new GuardianException('The service record could not be activated.');
            }
        } finally {
            foreach ([$stagedDocument, $stagedEnvelope, $backupDocument, $backupEnvelope] as $temporary) {
                if (is_file($temporary)) {
                    @unlink($temporary);
                }
            }
            $lock->release();
            unset($directory);
        }
    }

    public function discard(): void
    {
        $lock = $this->lockFactory->create(self::LOCK, 60);
        $this->acquire($lock);
        try {
            foreach ([self::DOCUMENT, self::ENVELOPE] as $name) {
                $path = $this->workingDirectory->resolve($name);
                if (is_file($path) && !@unlink($path)) {
                    throw new GuardianException('The stored service record could not be removed.');
                }
            }
        } finally {
            $lock->release();
        }
    }

    public function verifiedHost(): string
    {
        $raw = $this->readFile(self::INSTALLATION);
        if ($raw === null) {
            return '';
        }
        $data = json_decode($raw, true);
        if (!\is_array($data) || !\is_string($data['host'] ?? null)) {
            return '';
        }

        return HostIdentity::normalize($data['host']);
    }

    public function rememberVerifiedHost(string $host): void
    {
        $host = HostIdentity::normalize($host);
        if ($host === '' || $host === $this->verifiedHost()) {
            return;
        }
        $encoded = json_encode(['host' => $host], \JSON_UNESCAPED_SLASHES);
        if ($encoded === false) {
            return;
        }
        try {
            $this->writeExact($this->workingDirectory->resolve(self::INSTALLATION) . '.staged', $encoded);
            @rename(
                $this->workingDirectory->resolve(self::INSTALLATION) . '.staged',
                $this->workingDirectory->resolve(self::INSTALLATION)
            );
            @chmod($this->workingDirectory->resolve(self::INSTALLATION), 0o640);
        } catch (GuardianException) {
            // A local observation that cannot be recorded must not break the request.
        }
    }

    private function rollback(
        bool $hadPrevious,
        string $backupDocument,
        string $documentPath,
        string $backupEnvelope,
        string $envelopePath,
    ): void {
        if ($hadPrevious && is_file($backupDocument) && is_file($backupEnvelope)) {
            @rename($backupDocument, $documentPath);
            @rename($backupEnvelope, $envelopePath);

            return;
        }
        // There was nothing valid before; leaving a half-written pair behind
        // would be worse than leaving the installation unlicensed.
        @unlink($documentPath);
        @unlink($envelopePath);
    }

    private function acquire(\Vtinnovations\GuardianTypo3\Application\Contract\LockInterface $lock): bool
    {
        for ($attempt = 0; $attempt < 50; $attempt++) {
            if ($lock->acquire()) {
                return true;
            }
            usleep(20000);
        }

        return false;
    }

    private function readFile(string $name): ?string
    {
        try {
            $path = $this->workingDirectory->resolve($name);
        } catch (GuardianException) {
            return null;
        }
        if (!is_file($path)) {
            return null;
        }
        $raw = @file_get_contents($path); // binary-safe: exact bytes matter

        return $raw === false ? null : $raw;
    }

    /** Writes exact bytes and flushes them to the filesystem before returning. */
    private function writeExact(string $path, string $bytes): void
    {
        $this->directory();
        $handle = @fopen($path, 'wb');
        if ($handle === false) {
            throw new GuardianException('The service record could not be written.');
        }
        try {
            if (@fwrite($handle, $bytes) !== strlen($bytes)) {
                throw new GuardianException('The service record could not be written.');
            }
            @fflush($handle);
        } finally {
            @fclose($handle);
        }
        @chmod($path, 0o640);
    }

    private function directory(): string
    {
        $directory = $this->workingDirectory->path();
        if (!is_dir($directory) && !@mkdir($directory, 0o750, true) && !is_dir($directory)) {
            throw new GuardianException('Guardian\'s working directory could not be created.');
        }

        return $directory;
    }
}
