<?php

declare(strict_types=1);

/*
 * This file is part of the "Guardian for TYPO3" extension for TYPO3 CMS.
 *
 * @author    V&T Innovations Team
 * @license   LGPL-3.0-or-later
 * @copyright V&T Innovations 2026 - 2028
 */

namespace Vtinnovations\GuardianTypo3\Infrastructure\Version;

/**
 * The verification keys pinned into this release.
 *
 * The ring is part of the shipped artefact, exactly like the release version this
 * package reports. It is never read from configuration, the environment, the
 * database or a remote answer, because a trust anchor that an attacker can supply
 * is not a trust anchor. In particular a replacement key is never taken from the
 * same response whose signature depends on it — rotation happens through a signed
 * release, not through the protocol.
 *
 * A ring with no usable key is a build defect, not a runtime mode. Every lookup
 * then fails, every signed exchange fails with it, and
 * {@see productionReadiness()} reports the reason so the release check can refuse
 * to package the extension. There is deliberately no switch that turns
 * verification off.
 *
 * @see \Vtinnovations\GuardianTypo3\Command\ReleaseCheckCommand for the build gate.
 */
final class ReleaseKeyring
{
    /** Diagnostic categories. They classify a failure; they never permit one. */
    public const NO_KEYS = 'signing_key_store_empty';
    public const UNKNOWN_KEY = 'unknown_signing_key';
    public const KEY_NOT_USABLE = 'signing_key_not_usable';

    /** @var array<string, SigningKey> */
    private array $keys;

    /** @var array<string, string> declared fingerprint per key id */
    private array $declaredFingerprints;

    /**
     * @param list<SigningKey>|null $keys         test seam; production always pins its own
     * @param array<string, string> $fingerprints declared fingerprint per key id
     *
     * A caller that hands over ready-made key objects has, by definition,
     * already chosen what to trust, so its keys vouch for themselves unless it
     * states otherwise. Production never takes that path: it passes no keys, and
     * the fingerprints then come from the separate declared list, which is what
     * makes the comparison meaningful.
     */
    public function __construct(?array $keys = null, ?array $fingerprints = null)
    {
        $entries = $keys ?? self::pinned();
        $this->declaredFingerprints = $fingerprints ?? ($keys === null
            ? self::declaredFingerprints()
            : self::selfDeclared($entries));
        $this->keys = [];
        foreach ($entries as $key) {
            // A duplicate identifier is ambiguous, so neither definition is kept.
            if (isset($this->keys[$key->id])) {
                unset($this->keys[$key->id]);
                continue;
            }
            $this->keys[$key->id] = $key;
        }
    }

    /**
     * Resolves the key an incoming packet claims to be signed with. Returns null
     * whenever the ring cannot vouch for that claim — no key at all, an unknown
     * identifier, an algorithm the key was not pinned for, a purpose it was not
     * pinned for, or a rotation window that has not opened or has closed.
     */
    public function resolve(string $keyId, string $algorithm, string $purpose, int $now): ?SigningKey
    {
        $key = $this->keys[trim($keyId)] ?? null;
        if ($key === null) {
            return null;
        }
        if (!hash_equals($key->algorithm, strtolower(trim($algorithm)))) {
            return null;
        }

        return $key->isUsableFor($purpose, $now) ? $key : null;
    }

    /**
     * Every key that could verify this purpose right now.
     *
     * The record document names no key id — only the envelope and the push request do — so its
     * signature is tried against each key the build holds. That is what keeps a record signed
     * before a rotation verifying after it, and it costs one extra verification at most.
     *
     * @return list<SigningKey>
     */
    public function usableFor(string $algorithm, string $purpose, int $now): array
    {
        $usable = [];
        $algorithm = strtolower(trim($algorithm));

        foreach ($this->keys as $key) {
            if (hash_equals($key->algorithm, $algorithm) && $key->isUsableFor($purpose, $now)) {
                $usable[] = $key;
            }
        }

        return $usable;
    }

    /** The diagnostic label explaining why {@see resolve()} returned null. */
    public function refusalCategory(string $keyId): string
    {
        if ($this->keys === []) {
            return self::NO_KEYS;
        }

        return isset($this->keys[trim($keyId)]) ? self::KEY_NOT_USABLE : self::UNKNOWN_KEY;
    }

    public function isEmpty(): bool
    {
        return $this->keys === [];
    }

    /** @return list<string> */
    public function keyIds(): array
    {
        return array_keys($this->keys);
    }

    /**
     * Whether this build may be distributed, and if not, why. The release check
     * command turns a non-empty problem list into a failing exit code.
     *
     * @return list<string> empty when the ring is fit to ship
     */
    public function productionReadiness(): array
    {
        $problems = [];
        if ($this->keys === []) {
            $problems[] = self::NO_KEYS . ': no verification key is pinned into this build.';
        }
        if (!\function_exists('sodium_crypto_sign_verify_detached')) {
            $problems[] = 'sodium_unavailable: the runtime cannot verify Ed25519 signatures.';
        }
        foreach ($this->keys as $id => $key) {
            if (strlen($key->publicKey) !== SigningKey::expectedKeyLength()) {
                $problems[] = 'malformed_key: "' . $id . '" does not have the expected key length.';
            }
            // A key generated to exercise a test or a local build must never
            // reach customers: it would verify nothing the vendor ever signed,
            // and the failure would look like a broken licence rather than a
            // broken release.
            if (self::looksDisposable($id)) {
                $problems[] = 'disposable_key: "' . $id . '" looks like a test or development key.';
            }
            // The key and its fingerprint arrive through different channels, so
            // comparing them here is what turns "a key was pasted in" into "the
            // key the issuer published was pasted in". A pinned key with no
            // declared fingerprint has not been through that check.
            $declared = $this->declaredFingerprints[$id] ?? '';
            if ($declared === '') {
                $problems[] = 'unverified_key: "' . $id . '" has no declared fingerprint to compare against.';
                continue;
            }
            if (!hash_equals(strtolower($declared), $key->fingerprint())) {
                $problems[] = 'fingerprint_mismatch: "' . $id . '" does not match its declared fingerprint.';
            }
        }

        return $problems;
    }

    /**
     * Fingerprints taken from the keys themselves, for an injected ring.
     *
     * @param list<SigningKey> $keys
     * @return array<string, string>
     */
    private static function selfDeclared(array $keys): array
    {
        $fingerprints = [];
        foreach ($keys as $key) {
            $fingerprints[$key->id] = $key->fingerprint();
        }

        return $fingerprints;
    }

    /**
     * Whether a key identifier names something that was never meant to ship.
     * This is a packaging safeguard, not a cryptographic one: a disposable key
     * is indistinguishable from an approved one by inspection, so the identifier
     * is the only honest signal available at build time.
     */
    private static function looksDisposable(string $keyId): bool
    {
        $candidate = strtolower($keyId);
        foreach (['test', 'dev', 'local', 'example', 'sample', 'dummy', 'tmp', 'temp', 'ephemeral', 'fake', 'staging'] as $marker) {
            if (str_contains($candidate, $marker)) {
                return true;
            }
        }

        return false;
    }

    /**
     * The keys pinned for this release.
     *
     * Each entry is produced by {@see SigningKey::pin()}, which refuses anything
     * that is not structurally sound, so an entry that survives is at least a
     * well-formed key of an accepted algorithm. Add an entry only from approved
     * vendor material delivered through the release channel; never from a
     * protocol response and never inferred from a key identifier.
     *
     * To pin a key, append:
     *
     *     SigningKey::pin(
     *         id:              'vtone-2026a',
     *         algorithm:       SigningKey::ALGORITHM_ED25519,
     *         publicKeyBase64: self::fragmentA() . self::fragmentB(),
     *         purposes:        [SigningKey::PURPOSE_ANY],
     *         activeFrom:      1767225600,
     *         retireAt:        null,
     *     ),
     *
     * and supply the two fragments below. Keeping the material split means the
     * complete key is not one greppable literal in the shipped artefact; it is a
     * packaging measure only, and the security of the scheme rests entirely on
     * the private half never leaving vendor infrastructure.
     *
     * @return list<SigningKey>
     */
    private static function pinned(): array
    {
        $pinned = [];
        foreach (self::material() as [$id, $algorithm, $encoded, $purposes, $activeFrom, $retireAt]) {
            $key = SigningKey::pin($id, $algorithm, $encoded, $purposes, $activeFrom, $retireAt);
            if ($key !== null) {
                $pinned[] = $key;
            }
        }

        return $pinned;
    }

    /**
     * Pinned key material in storage form. This build carries the approved vendor
     * key `vtone-2026a`; a build that carried none would fail every signed
     * exchange closed with {@see NO_KEYS} and the release check would refuse to
     * package it.
     *
     * Change this only with `php Build/pin-verification-key.php` or
     * `php Build/import-integration-profile.php`, never by hand: both refuse a
     * value that is not a 32-byte Ed25519 public key, both cross-check the
     * issuer's published fingerprint, and both refuse to overwrite a key that is
     * already trusted.
     *
     * @return list<array{0: string, 1: string, 2: string, 3: list<string>, 4: int, 5: int|null}>
     */
    private static function material(): array
    {
        return [
            ['vtone-2026a', 'ed25519', 'qllgm+66FUVBFJ3O68ICFG' . '8b37dR+9jMfr1+4/pSygE=', ['*'], 0, null],
        ];
    }

    /**
     * The fingerprint each pinned key is expected to have, keyed by key id.
     *
     * This is deliberately a separate list from the material above. The issuer
     * publishes the key and the fingerprint through different channels, and
     * keeping them apart here means a single altered paste — or a single altered
     * file in a supply-chain sense — fails the release check instead of being
     * adopted silently.
     *
     * @return array<string, string> lower-case SHA-256 of the raw key, first 16 hex characters
     */
    private static function declaredFingerprints(): array
    {
        return [
            'vtone-2026a' => 'edcd614e70c59ce0',
        ];
    }
}
