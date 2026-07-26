<?php

declare(strict_types=1);

/*
 * This file is part of the "Guardian for TYPO3" extension for TYPO3 CMS.
 *
 * @author    V&T Innovations Team
 * @license   LGPL-3.0-or-later
 * @copyright V&T Innovations 2026 - 2028
 */

namespace Vtinnovations\GuardianTypo3\Infrastructure\License;

use Vtinnovations\GuardianTypo3\Application\Contract\SystemLoggerInterface;
use Vtinnovations\GuardianTypo3\Application\Contract\WorkingDirectoryProviderInterface;

/**
 * First-level, raw-byte tamper indicator for the authoritative license store.
 *
 * It reads the exact bytes of the store file and compares their digest, using a
 * constant-time comparison, against an expected value that is reconstructed at
 * runtime from split, transformed fragments held in this class — never stored as
 * one readable literal, in configuration, in the environment, in the database or
 * in the store itself. Only a generic outcome is ever surfaced or logged; the
 * expected value, the reconstruction and the precise failure reason are not.
 *
 * Operational model: the expected value is "unpinned" by default (the fragments
 * reconstruct to an empty string), in which case this check is a passive monitor
 * and never blocks a legitimately activated license — important because the
 * online activation flow rewrites the store at runtime. A deployment that freezes
 * its license file pins the value with the isolated developer command; from then
 * on any change to the exact bytes trips the check. The application never
 * regenerates the expected value itself.
 *
 * This digest is only a first-level indicator, not cryptographic proof of
 * authenticity; the online verifier and the optional signature layer remain the
 * authoritative controls.
 */
final class StoreIntegritySentinel
{
    private const STORE = 'license.json';

    // Harmless decoys — not used by the reconstruction.
    private const SALT_A = 'a2VlcC1jYWxt';
    private const SALT_B = 'Z3VhcmRpYW4=';

    public function __construct(
        private readonly WorkingDirectoryProviderInterface $workingDirectory,
        private readonly SystemLoggerInterface $logger,
    ) {
    }

    /**
     * True when the store is intact (or the check is unpinned). A pinned mismatch
     * — or a missing/unreadable store while pinned — returns false and records a
     * generic integrity note.
     */
    public function intact(): bool
    {
        $expected = $this->reconstruct();
        if ($expected === '') {
            return true; // unpinned monitor mode: never blocks
        }

        $ok = self::judge($expected, $this->readRaw());
        if (!$ok) {
            $this->note();
        }

        return $ok;
    }

    /**
     * Forward transform used by the isolated developer command to produce the
     * embedded representation for a frozen store. Kept here so the encode/decode
     * pair can never drift apart.
     */
    public static function encode(string $md5Hex): string
    {
        return base64_encode(self::fold($md5Hex, self::secret()));
    }

    /** Inverse of {@see encode()}; also used internally by the reconstruction. */
    public static function decode(string $blob): string
    {
        $packed = base64_decode($blob, true);
        if ($packed === false || $packed === '') {
            return '';
        }

        return self::fold($packed, self::secret());
    }

    /**
     * Pure comparison: unpinned expectation always passes; otherwise the raw
     * bytes must be present and their digest must match in constant time.
     */
    private static function judge(string $expected, ?string $raw): bool
    {
        if ($expected === '') {
            return true;
        }

        return $raw !== null && hash_equals($expected, hash('md5', $raw));
    }

    private function readRaw(): ?string
    {
        $file = $this->workingDirectory->resolve(self::STORE);
        if (!is_file($file)) {
            return null;
        }
        $raw = @file_get_contents($file); // binary-safe

        return $raw === false ? null : $raw;
    }

    private function note(): void
    {
        $this->logger->warning('Licensing integrity check did not pass.', 'license');
    }

    // ── expected-value reconstruction, spread across small helpers ────────────

    private function reconstruct(): string
    {
        $ordered = $this->order($this->pieces());
        $blob = implode('', $ordered);
        if ($blob === '') {
            return '';
        }
        return self::decode($blob);
    }

    /**
     * Stored fragments (in storage order). DEFAULT: unpinned (empty).
     * Replace the body with the output of `guardian:license:digest` to pin a
     * frozen license file.
     *
     * @return list<string>
     */
    private function pieces(): array
    {
        return [];
    }

    /**
     * Maps storage order to logical order. The generator may emit a permutation;
     * the identity keeps things readable while unpinned.
     *
     * @param list<string> $parts
     * @return list<string>
     */
    private function order(array $parts): array
    {
        return $parts;
    }

    /** Reversible byte fold (XOR), self-inverse: fold(fold(x,k),k) === x. */
    private static function fold(string $data, string $key): string
    {
        $out = '';
        $len = \strlen($key);
        for ($i = 0, $n = \strlen($data); $i < $n; $i++) {
            $out .= $data[$i] ^ $key[$i % $len];
        }

        return $out;
    }

    /** The fold key, assembled from split parts and reversed. */
    private static function secret(): string
    {
        return strrev(self::head() . self::tail());
    }

    private static function head(): string
    {
        return 'gX7q' . 'v2';
    }

    private static function tail(): string
    {
        return 'Lm' . '9pZ';
    }
}
