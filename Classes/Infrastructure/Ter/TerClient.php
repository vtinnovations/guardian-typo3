<?php

declare(strict_types=1);

/*
 * This file is part of the "Guardian for TYPO3" extension for TYPO3 CMS.
 *
 * @author    V&T Innovations Team
 * @license   LGPL-3.0-or-later
 * @copyright V&T Innovations 2026 - 2028
 */

namespace Vtinnovations\GuardianTypo3\Infrastructure\Ter;

use Vtinnovations\GuardianTypo3\Application\Contract\WorkingDirectoryProviderInterface;
use Vtinnovations\GuardianTypo3\Domain\Exception\GuardianException;

/**
 * Talks to the official TYPO3 Extension Repository REST API over FIXED, trusted
 * HTTPS endpoints only. Browser input never becomes part of the host or path
 * structure: the query and extension key are validated against strict patterns
 * and URL-encoded, and the base URLs are compile-time constants.
 *
 * Responses are decoded as JSON (never eval'd) and cached in a private,
 * size-bounded, TTL'd on-disk cache under the Guardian working directory so a
 * burst of searches does not hammer the API. All failures are surfaced as
 * machine-readable codes (no URLs, no credentials, no stack traces).
 */
final class TerClient
{
    private const BASE = 'https://extensions.typo3.org/api/v1';
    private const CACHE_DIR = 'ter';
    private const CACHE_TTL = 3600;      // 1 hour
    private const CACHE_MAX_FILES = 200; // bounded private cache

    public function __construct(
        private readonly TerHttpTransportInterface $transport,
        private readonly WorkingDirectoryProviderInterface $workingDirectory,
    ) {
    }

    /**
     * Exact extension-key lookup against the real TER endpoint
     * `GET /api/v1/extension/{key}`. Returns the decoded metadata, or NULL when
     * the TER has no such key (HTTP 404) — a genuine "no match", NOT an error.
     * Transport failures (DNS/TLS/timeout/unreachable) and unsupported responses
     * propagate as their precise codes and are never turned into "not found".
     *
     * @return array<string, mixed>|null
     * @throws GuardianException on transport / HTTP / schema failure
     */
    public function extensionOrNull(string $extensionKey): ?array
    {
        if (preg_match('/^[a-z0-9_]{2,60}$/', $extensionKey) !== 1) {
            throw new GuardianException('ter_invalid_extension_key');
        }
        $data = $this->getJson(self::BASE . '/extension/' . rawurlencode($extensionKey), true);
        if ($data === null) {
            return null; // 404 → not in TER
        }

        // The endpoint may return the extension directly or wrapped.
        if (isset($data['extension']) && \is_array($data['extension'])) {
            return $data['extension'];
        }
        if (array_is_list($data) && isset($data[0]) && \is_array($data[0])) {
            return $data[0];
        }

        return $data;
    }

    /**
     * @param bool $nullOn404 when true a 404 returns null instead of throwing
     * @return array<int|string, mixed>|null
     * @throws GuardianException
     */
    private function getJson(string $url, bool $nullOn404 = false): ?array
    {
        $cached = $this->readCache($url);
        if ($cached !== null) {
            return $cached;
        }

        $response = $this->transport->get($url);
        $status = $response['status'];
        if ($status === 404 && $nullOn404) {
            return null;
        }
        if ($status === 429) {
            throw new GuardianException('ter_rate_limited');
        }
        if ($status < 200 || $status >= 300) {
            throw new GuardianException('ter_http_error');
        }
        $decoded = json_decode($response['body'], true);
        if (!\is_array($decoded)) {
            throw new GuardianException('unsupported_schema');
        }

        $this->writeCache($url, $decoded);

        return $decoded;
    }

    /**
     * @return array<int|string, mixed>|null
     */
    private function readCache(string $url): ?array
    {
        $file = $this->cacheFile($url);
        if (!is_file($file) || (time() - (int) @filemtime($file)) > self::CACHE_TTL) {
            return null;
        }
        $data = json_decode((string) @file_get_contents($file), true);

        return \is_array($data) ? $data : null;
    }

    /**
     * @param array<int|string, mixed> $data
     */
    private function writeCache(string $url, array $data): void
    {
        $dir = $this->workingDirectory->resolve(self::CACHE_DIR);
        if (!is_dir($dir) && !@mkdir($dir, 0o750, true) && !is_dir($dir)) {
            return;
        }
        $this->prune($dir);
        @file_put_contents($this->cacheFile($url), json_encode($data), \LOCK_EX);
    }

    private function cacheFile(string $url): string
    {
        return $this->workingDirectory->resolve(self::CACHE_DIR . '/' . sha1($url) . '.json');
    }

    private function prune(string $dir): void
    {
        $files = glob($dir . '/*.json') ?: [];
        if (\count($files) < self::CACHE_MAX_FILES) {
            return;
        }
        usort($files, static fn (string $a, string $b): int => (int) @filemtime($a) <=> (int) @filemtime($b));
        foreach (\array_slice($files, 0, \count($files) - self::CACHE_MAX_FILES + 1) as $old) {
            @unlink($old);
        }
    }
}
