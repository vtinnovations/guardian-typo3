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

use Vtinnovations\GuardianTypo3\Application\Contract\WorkingDirectoryProviderInterface;

/**
 * Brute-force protection for the standalone recovery panel login.
 *
 * State lives OUTSIDE the web root (var/guardian/recovery-panel/rate-limit.json)
 * and is keyed by a SHA-256 hash of the client IP — the raw IP is never stored.
 * After MAX_ATTEMPTS failures inside WINDOW seconds a client is locked out for
 * LOCKOUT seconds. Entries auto-expire, so the file never grows without bound.
 * The panel always returns a single generic failure message and never reveals
 * whether the token prefix was correct.
 */
final class PanelRateLimiter
{
    private const FILE = 'recovery-panel/rate-limit.json';
    private const MAX_ATTEMPTS = 5;
    private const WINDOW = 900;    // 15 minutes
    private const LOCKOUT = 900;   // 15 minutes

    public function __construct(
        private readonly WorkingDirectoryProviderInterface $workingDirectory,
    ) {
    }

    /**
     * @return array{locked: bool, retryAfter: int}
     */
    public function check(string $clientIp): array
    {
        $now = time();
        $key = $this->key($clientIp);
        $state = $this->prune($this->read(), $now);
        $entry = $state[$key] ?? null;
        if (\is_array($entry) && ($entry['locked_until'] ?? 0) > $now) {
            return ['locked' => true, 'retryAfter' => (int) $entry['locked_until'] - $now];
        }

        return ['locked' => false, 'retryAfter' => 0];
    }

    public function registerFailure(string $clientIp): void
    {
        $now = time();
        $key = $this->key($clientIp);
        $state = $this->prune($this->read(), $now);
        $entry = $state[$key] ?? ['count' => 0, 'first' => $now, 'locked_until' => 0];
        if (($entry['first'] ?? $now) < $now - self::WINDOW) {
            $entry = ['count' => 0, 'first' => $now, 'locked_until' => 0];
        }
        $entry['count'] = (int) ($entry['count'] ?? 0) + 1;
        if ($entry['count'] >= self::MAX_ATTEMPTS) {
            $entry['locked_until'] = $now + self::LOCKOUT;
        }
        $state[$key] = $entry;
        $this->write($state);
    }

    public function registerSuccess(string $clientIp): void
    {
        $state = $this->read();
        unset($state[$this->key($clientIp)]);
        $this->write($state);
    }

    private function key(string $clientIp): string
    {
        return hash('sha256', 'guardian-panel|' . trim($clientIp));
    }

    /**
     * @param array<string, mixed> $state
     * @return array<string, mixed>
     */
    private function prune(array $state, int $now): array
    {
        foreach ($state as $key => $entry) {
            if (!\is_array($entry)) {
                unset($state[$key]);
                continue;
            }
            $lockedUntil = (int) ($entry['locked_until'] ?? 0);
            $first = (int) ($entry['first'] ?? 0);
            if ($lockedUntil <= $now && $first < $now - self::WINDOW) {
                unset($state[$key]);
            }
        }

        return $state;
    }

    /**
     * @return array<string, mixed>
     */
    private function read(): array
    {
        $file = $this->workingDirectory->resolve(self::FILE);
        if (!is_file($file)) {
            return [];
        }
        $decoded = json_decode((string) @file_get_contents($file), true);

        return \is_array($decoded) ? $decoded : [];
    }

    /**
     * @param array<string, mixed> $state
     */
    private function write(array $state): void
    {
        $file = $this->workingDirectory->resolve(self::FILE);
        $dir = \dirname($file);
        if (!is_dir($dir)) {
            @mkdir($dir, 0o750, true);
        }
        @file_put_contents($file, json_encode($state, \JSON_PRETTY_PRINT), \LOCK_EX);
        @chmod($file, 0o600);
    }
}
