<?php

declare(strict_types=1);

/*
 * This file is part of the "Guardian for TYPO3" extension for TYPO3 CMS.
 *
 * @author    V&T Innovations Team
 * @license   LGPL-3.0-or-later
 * @copyright V&T Innovations 2026 - 2028
 */

namespace Vtinnovations\GuardianTypo3\Infrastructure\Exchange;

use Vtinnovations\GuardianTypo3\Application\Contract\LockFactoryInterface;
use Vtinnovations\GuardianTypo3\Application\Contract\LockInterface;
use Vtinnovations\GuardianTypo3\Application\Contract\WorkingDirectoryProviderInterface;

/**
 * Remembers which inbound requests have already been handled, so an honest retry
 * succeeds without doing the work twice and a dishonest replay does not succeed
 * at all.
 *
 * Two independent facts are kept. A one-use value is remembered by its digest,
 * which is enough to notice it a second time without ever writing the value
 * itself down. A request identifier is remembered together with a fingerprint of
 * the authenticated content it arrived with, so the same identifier presented
 * with different content is recognised as a conflict rather than waved through as
 * a repeat.
 *
 * Nothing here stores a document, a key, a signature or a raw one-use value.
 * Entries expire well after the window in which a retry is plausible, and the
 * file is capped so it cannot grow without bound.
 *
 * The reservation is taken under the same exclusive lock that later records the
 * result, so two requests arriving at once cannot both decide they are the first.
 * On a multi-node installation this file must live on storage shared by every
 * node, or the journal must be replaced by an adapter backed by a shared
 * transactional store.
 */
final class RequestJournal
{
    private const FILE = 'exchange-journal.json';
    private const LOCK = 'exchange-journal';

    /** Retained far beyond any plausible retry, then pruned. */
    private const RETENTION_SECONDS = 172800;
    private const MAX_ENTRIES = 500;

    public function __construct(
        private readonly WorkingDirectoryProviderInterface $workingDirectory,
        private readonly LockFactoryInterface $lockFactory,
    ) {
    }

    /**
     * Claims the right to process a request.
     *
     * Returns an existing entry when this identifier has been seen before — the
     * caller compares fingerprints to tell a legitimate retry from a conflict —
     * or null when the claim succeeded and the caller should proceed. A one-use
     * value that has already been consumed produces a refused claim.
     */
    public function claim(string $requestId, string $fingerprint, string $nonceDigest, int $now): JournalClaim
    {
        if ($requestId === '' || $fingerprint === '' || $nonceDigest === '') {
            return JournalClaim::refused();
        }

        $lock = $this->lockFactory->create(self::LOCK, 60);
        if (!$this->acquire($lock)) {
            return JournalClaim::refused();
        }

        try {
            $journal = $this->read($now);

            $existing = $journal['requests'][$requestId] ?? null;
            if (\is_array($existing)) {
                return JournalClaim::seen(
                    (string) ($existing['fingerprint'] ?? ''),
                    (string) ($existing['result'] ?? ''),
                    isset($existing['version']) ? (int) $existing['version'] : null,
                );
            }

            // A one-use value is only ever rejected for a request identifier we
            // have not seen; a genuine retry reuses both and is handled above.
            if (isset($journal['nonces'][$nonceDigest])) {
                return JournalClaim::refused();
            }

            $journal['nonces'][$nonceDigest] = $now;
            $journal['requests'][$requestId] = [
                'fingerprint' => $fingerprint,
                'result' => 'pending',
                'version' => null,
                'at' => $now,
            ];
            $this->write($journal);

            return JournalClaim::granted();
        } finally {
            $lock->release();
        }
    }

    /** Records how a claimed request ended. */
    public function settle(string $requestId, string $result, ?int $version, int $now): void
    {
        if ($requestId === '') {
            return;
        }
        $lock = $this->lockFactory->create(self::LOCK, 60);
        if (!$this->acquire($lock)) {
            return;
        }
        try {
            $journal = $this->read($now);
            if (!isset($journal['requests'][$requestId])) {
                return;
            }
            $journal['requests'][$requestId]['result'] = $result;
            $journal['requests'][$requestId]['version'] = $version;
            $journal['requests'][$requestId]['at'] = $now;
            $this->write($journal);
        } finally {
            $lock->release();
        }
    }

    /**
     * Removes a claim that turned out not to be processable, so a corrected
     * retry with the same identifier is not blocked by a reservation that never
     * did anything.
     */
    public function release(string $requestId, int $now): void
    {
        if ($requestId === '') {
            return;
        }
        $lock = $this->lockFactory->create(self::LOCK, 60);
        if (!$this->acquire($lock)) {
            return;
        }
        try {
            $journal = $this->read($now);
            unset($journal['requests'][$requestId]);
            $this->write($journal);
        } finally {
            $lock->release();
        }
    }

    /**
     * @return array{nonces: array<string, int>, requests: array<string, array<string, mixed>>}
     */
    private function read(int $now): array
    {
        $journal = ['nonces' => [], 'requests' => []];
        $path = $this->path();
        if (is_file($path)) {
            $decoded = json_decode((string) @file_get_contents($path), true);
            if (\is_array($decoded)) {
                $journal['nonces'] = \is_array($decoded['nonces'] ?? null) ? $decoded['nonces'] : [];
                $journal['requests'] = \is_array($decoded['requests'] ?? null) ? $decoded['requests'] : [];
            }
        }

        return $this->prune($journal, $now);
    }

    /**
     * @param array{nonces: array<string, int>, requests: array<string, array<string, mixed>>} $journal
     * @return array{nonces: array<string, int>, requests: array<string, array<string, mixed>>}
     */
    private function prune(array $journal, int $now): array
    {
        foreach ($journal['nonces'] as $digest => $seenAt) {
            if (!\is_int($seenAt) || ($now - $seenAt) > self::RETENTION_SECONDS) {
                unset($journal['nonces'][$digest]);
            }
        }
        foreach ($journal['requests'] as $id => $entry) {
            $at = \is_array($entry) ? (int) ($entry['at'] ?? 0) : 0;
            if ($at === 0 || ($now - $at) > self::RETENTION_SECONDS) {
                unset($journal['requests'][$id]);
            }
        }
        $journal['nonces'] = $this->cap($journal['nonces']);
        $journal['requests'] = $this->cap($journal['requests']);

        return $journal;
    }

    /**
     * @param array<string, mixed> $entries
     * @return array<string, mixed>
     */
    private function cap(array $entries): array
    {
        return \count($entries) <= self::MAX_ENTRIES
            ? $entries
            : \array_slice($entries, -self::MAX_ENTRIES, null, true);
    }

    /**
     * @param array{nonces: array<string, int>, requests: array<string, array<string, mixed>>} $journal
     */
    private function write(array $journal): void
    {
        $path = $this->path();
        $directory = \dirname($path);
        if (!is_dir($directory) && !@mkdir($directory, 0o750, true) && !is_dir($directory)) {
            return;
        }
        $encoded = json_encode($journal, \JSON_UNESCAPED_SLASHES);
        if ($encoded === false) {
            return;
        }
        $staged = $path . '.staged';
        if (@file_put_contents($staged, $encoded) === false || !@rename($staged, $path)) {
            @unlink($staged);

            return;
        }
        @chmod($path, 0o640);
    }

    private function acquire(LockInterface $lock): bool
    {
        for ($attempt = 0; $attempt < 50; $attempt++) {
            if ($lock->acquire()) {
                return true;
            }
            usleep(20000);
        }

        return false;
    }

    private function path(): string
    {
        return $this->workingDirectory->resolve(self::FILE);
    }
}
