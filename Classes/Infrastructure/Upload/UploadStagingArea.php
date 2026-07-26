<?php

declare(strict_types=1);

/*
 * This file is part of the "Guardian for TYPO3" extension for TYPO3 CMS.
 *
 * @author    V&T Innovations Team
 * @license   LGPL-3.0-or-later
 * @copyright V&T Innovations 2026 - 2028
 */

namespace Vtinnovations\GuardianTypo3\Infrastructure\Upload;

use Psr\Http\Message\UploadedFileInterface;
use Vtinnovations\GuardianTypo3\Application\Contract\WorkingDirectoryProviderInterface;
use Vtinnovations\GuardianTypo3\Domain\Exception\GuardianException;
use Vtinnovations\GuardianTypo3\Infrastructure\Archive\ZipSafetyInspector;

/**
 * A PRIVATE staging area for uploaded extension archives, located INSIDE the
 * project under the single Guardian runtime root:
 *
 *   <project>/var/guardian/extensions/uploads/<random-upload-id>/archive.zip
 *
 * Staging inside var/guardian (rather than the system temp dir) is deliberate:
 * on hardened hosts (Plesk / open_basedir) `move_uploaded_file()` into /tmp is
 * blocked and throws a RuntimeException — the reported failure. The project's
 * var/ is always inside open_basedir and owned by the PHP process user.
 *
 * The whole directory hierarchy is prepared (recursively, checked, writable,
 * restrictive owner-only 0700/0750 — never world-writable) before the file is
 * moved. The uploaded
 * file is moved with {@see UploadedFileInterface::moveTo()}; if that fails (e.g.
 * cross-filesystem), a bounded, memory-safe stream-copy fallback is used, the
 * byte count is verified, and the part file is atomically renamed into place. No
 * browser-supplied path is ever trusted; the destination is a fixed internal
 * name and is asserted to stay inside the upload root. It extracts (via
 * {@see ZipSafetyInspector}) only into the id's private `extracted/` dir.
 */
final class UploadStagingArea
{
    private const ROOT = 'extensions/uploads';
    private const ARCHIVE_NAME = 'archive.zip';
    private const MAX_UPLOAD_BYTES = 60 * 1024 * 1024; // hard maximum ZIP size
    private const CHUNK = 65536;

    public function __construct(
        private readonly WorkingDirectoryProviderInterface $workingDirectory,
        private readonly ZipSafetyInspector $inspector,
    ) {
    }

    public function maxUploadBytes(): int
    {
        return self::MAX_UPLOAD_BYTES;
    }

    /**
     * The private runtime area recipients see in a safe error detail (relative).
     */
    public function runtimeArea(): string
    {
        return 'var/guardian/' . self::ROOT;
    }

    /**
     * Stage a PSR-7 uploaded file directly into the private upload directory —
     * no system temp dir, no client path. Validates, prepares the hierarchy,
     * moves (or stream-copies) the file, verifies it and extracts it.
     *
     * @return array{token: string, checksum: string, archive: string, extracted: string, filename: string}
     * @throws GuardianException precise machine reason code on failure
     */
    public function acceptUploadedFile(UploadedFileInterface $file): array
    {
        if ($file->getError() !== \UPLOAD_ERR_OK) {
            throw new GuardianException('upload_incomplete');
        }
        $size = (int) $file->getSize();
        if ($size <= 0) {
            throw new GuardianException('upload_missing');
        }
        if ($size > self::MAX_UPLOAD_BYTES) {
            throw new GuardianException('upload_too_large');
        }
        $filename = $this->safeFilename($file->getClientFilename());

        $id = $this->newUploadId();
        $dir = $this->prepareUploadDir($id, $size);
        $archive = $dir . '/' . self::ARCHIVE_NAME;
        $this->assertInsideRoot($archive);

        try {
            $this->moveUploadedFile($file, $archive, $size);

            return $this->finalize($id, $dir, $archive, $filename);
        } catch (GuardianException $e) {
            $this->removeTree($dir);
            throw $e;
        }
    }

    /**
     * Store an already-materialised upload (a $_FILES-style array with a real
     * temp path) — used by the CLI/test path. Same private location + hierarchy.
     *
     * @param array{tmp_name?: string, name?: string, size?: int, error?: int} $file
     * @return array{token: string, checksum: string, archive: string, extracted: string, filename: string}
     * @throws GuardianException
     */
    public function accept(array $file): array
    {
        if ((int) ($file['error'] ?? \UPLOAD_ERR_NO_FILE) !== \UPLOAD_ERR_OK) {
            throw new GuardianException('upload_incomplete');
        }
        $tmp = (string) ($file['tmp_name'] ?? '');
        if ($tmp === '' || !is_file($tmp) || !is_readable($tmp)) {
            throw new GuardianException('upload_missing');
        }
        $size = (int) ($file['size'] ?? filesize($tmp) ?: 0);
        if ($size <= 0) {
            throw new GuardianException('upload_missing');
        }
        if ($size > self::MAX_UPLOAD_BYTES) {
            throw new GuardianException('upload_too_large');
        }
        $filename = $this->safeFilename((string) ($file['name'] ?? 'extension.zip'));

        $id = $this->newUploadId();
        $dir = $this->prepareUploadDir($id, $size);
        $archive = $dir . '/' . self::ARCHIVE_NAME;
        $this->assertInsideRoot($archive);

        try {
            if (!$this->relocate($tmp, $archive)) {
                throw new GuardianException('upload_move_failed');
            }

            return $this->finalize($id, $dir, $archive, $filename);
        } catch (GuardianException $e) {
            $this->removeTree($dir);
            throw $e;
        }
    }

    /**
     * @return array{token: string, archive: string, extracted: string, checksum: string, filename: string}
     * @throws GuardianException
     */
    public function get(string $token): array
    {
        $this->assertToken($token);
        $dir = $this->tokenDir($token);
        $archive = $dir . '/' . self::ARCHIVE_NAME;
        $extracted = $dir . '/extracted';
        if (!is_dir($dir) || !is_file($archive) || !is_dir($extracted)) {
            throw new GuardianException('staging_not_found');
        }

        $meta = is_file($dir . '/meta.json') ? json_decode((string) @file_get_contents($dir . '/meta.json'), true) : null;
        $filename = \is_array($meta) && \is_string($meta['filename'] ?? null) ? $meta['filename'] : '';

        return [
            'token' => $token,
            'archive' => $archive,
            'extracted' => $extracted,
            'checksum' => (string) hash_file('sha256', $archive),
            'filename' => $filename,
        ];
    }

    public function cleanup(string $token): void
    {
        $this->assertToken($token);
        $this->removeTree($this->tokenDir($token));
    }

    // ── directory preparation (one runtime-path system) ───────────────────────

    /**
     * Prepare var/guardian/ → extensions/ → extensions/uploads/ → <id>/, with
     * recursive, success-checked, writable, restrictive (0700/0750) creation.
     *
     * @throws GuardianException upload_root_creation_failed | upload_root_not_writable
     *                           | upload_directory_creation_failed | upload_disk_space_insufficient
     */
    private function prepareUploadDir(string $id, int $size): string
    {
        $root = $this->workingDirectory->resolve(self::ROOT);
        if (!is_dir($root) && !@mkdir($root, 0o750, true) && !is_dir($root)) {
            throw new GuardianException('upload_root_creation_failed');
        }
        if (!is_writable($root)) {
            throw new GuardianException('upload_root_not_writable');
        }

        // Ensure the staging filesystem has room for the file + extraction.
        $free = @disk_free_space($root);
        if ($free !== false && $size > 0 && $free < $size * 2) {
            throw new GuardianException('upload_disk_space_insufficient');
        }

        $dir = $root . '/' . $id;
        if (!@mkdir($dir, 0o700, true) && !is_dir($dir)) {
            throw new GuardianException('upload_directory_creation_failed');
        }
        if (!is_writable($dir)) {
            throw new GuardianException('upload_root_not_writable');
        }

        return $dir;
    }

    // ── moving the upload safely ──────────────────────────────────────────────

    /**
     * @throws GuardianException
     */
    private function moveUploadedFile(UploadedFileInterface $file, string $archive, int $size): void
    {
        try {
            $file->moveTo($archive);
        } catch (\Throwable) {
            // moveTo can fail across filesystems / under open_basedir / when the
            // implementation already streamed the body — fall back to a bounded,
            // memory-safe stream copy that stays inside the staging directory.
            $this->streamCopy($file, $archive, $size);
        }

        if (!is_file($archive) || !is_readable($archive)) {
            throw new GuardianException('upload_move_failed');
        }
        $actual = filesize($archive);
        if ($actual === false || ($size > 0 && $actual !== $size)) {
            @unlink($archive);
            throw new GuardianException('upload_size_mismatch');
        }
        @chmod($archive, 0o640);
    }

    /**
     * Bounded stream copy → part file → atomic rename. Never buffers the whole
     * upload in memory and never uses the client path or a shell.
     *
     * @throws GuardianException
     */
    private function streamCopy(UploadedFileInterface $file, string $archive, int $size): void
    {
        $stream = $file->getStream();
        if (!$stream->isReadable()) {
            throw new GuardianException('upload_missing');
        }
        $part = $archive . '.part';
        $out = @fopen($part, 'wb');
        if ($out === false) {
            throw new GuardianException('upload_stream_copy_failed');
        }

        $written = 0;
        try {
            if ($stream->isSeekable()) {
                $stream->rewind();
            }
            while (!$stream->eof()) {
                $chunk = $stream->read(self::CHUNK);
                if ($chunk === '') {
                    break;
                }
                $n = fwrite($out, $chunk);
                if ($n === false) {
                    throw new GuardianException('upload_stream_copy_failed');
                }
                $written += $n;
                if ($written > self::MAX_UPLOAD_BYTES) {
                    throw new GuardianException('upload_too_large');
                }
            }
            fflush($out);
        } catch (GuardianException $e) {
            fclose($out);
            @unlink($part);
            throw $e;
        }
        fclose($out);

        if ($size > 0 && $written !== $size) {
            @unlink($part);
            throw new GuardianException('upload_size_mismatch');
        }
        if (!@rename($part, $archive)) {
            @unlink($part);
            throw new GuardianException('upload_move_failed');
        }
    }

    /**
     * Move an already-materialised temp file into the staging archive (test/CLI).
     */
    private function relocate(string $tmp, string $archive): bool
    {
        if (is_uploaded_file($tmp) && @move_uploaded_file($tmp, $archive)) {
            return true;
        }

        return @rename($tmp, $archive) || @copy($tmp, $archive);
    }

    /**
     * Checksum, extract, and return the descriptor.
     *
     * @return array{token: string, checksum: string, archive: string, extracted: string, filename: string}
     * @throws GuardianException
     */
    private function finalize(string $id, string $dir, string $archive, string $filename): array
    {
        @chmod($archive, 0o640);
        // Persist the original filename so the version fallback survives to
        // inspection time (get() only sees the on-disk staging dir).
        @file_put_contents($dir . '/meta.json', json_encode(['filename' => $filename], \JSON_UNESCAPED_SLASHES), \LOCK_EX);
        @chmod($dir . '/meta.json', 0o640);
        $checksum = (string) hash_file('sha256', $archive);
        $extracted = $dir . '/extracted';
        $this->inspector->extractTo($archive, $extracted); // throws zip_* on unsafe/invalid

        return [
            'token' => $id,
            'checksum' => $checksum,
            'archive' => $archive,
            'extracted' => $extracted,
            'filename' => $filename,
        ];
    }

    // ── helpers ───────────────────────────────────────────────────────────────

    private function newUploadId(): string
    {
        return bin2hex(random_bytes(16)); // 32 hex chars, cryptographically random
    }

    private function safeFilename(?string $clientName): string
    {
        $name = basename((string) ($clientName ?? 'extension.zip'));
        $name = preg_replace('/[^A-Za-z0-9._-]/', '_', $name) ?? 'extension.zip';
        if ($name === '' || strtolower((string) pathinfo($name, \PATHINFO_EXTENSION)) !== 'zip') {
            throw new GuardianException('upload_not_zip');
        }

        return $name;
    }

    private function assertToken(string $token): void
    {
        if (preg_match('/^[a-f0-9]{32}$/', $token) !== 1) {
            throw new GuardianException('staging_invalid_token');
        }
    }

    private function tokenDir(string $token): string
    {
        return $this->workingDirectory->resolve(self::ROOT . '/' . $token);
    }

    /**
     * Defence-in-depth: the resolved destination must stay inside the upload root.
     */
    private function assertInsideRoot(string $path): void
    {
        $root = rtrim($this->workingDirectory->resolve(self::ROOT), '/');
        $normalized = rtrim($this->normalize($path), '/');
        if ($normalized !== $root && !str_starts_with($normalized, $root . '/')) {
            throw new GuardianException('upload_destination_invalid');
        }
    }

    private function normalize(string $path): string
    {
        $isAbsolute = str_starts_with($path, '/');
        $out = [];
        foreach (explode('/', $path) as $segment) {
            if ($segment === '' || $segment === '.') {
                continue;
            }
            if ($segment === '..') {
                array_pop($out);
                continue;
            }
            $out[] = $segment;
        }

        return ($isAbsolute ? '/' : '') . implode('/', $out);
    }

    private function removeTree(string $dir): void
    {
        if (!is_dir($dir)) {
            @unlink($dir);

            return;
        }
        $iterator = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($dir, \FilesystemIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::CHILD_FIRST
        );
        foreach ($iterator as $item) {
            $item->isDir() && !$item->isLink() ? @rmdir($item->getPathname()) : @unlink($item->getPathname());
        }
        @rmdir($dir);
    }
}
