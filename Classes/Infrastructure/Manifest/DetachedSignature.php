<?php

declare(strict_types=1);

/*
 * This file is part of the "Guardian for TYPO3" extension for TYPO3 CMS.
 *
 * @author    V&T Innovations Team
 * @license   LGPL-3.0-or-later
 * @copyright V&T Innovations 2026 - 2028
 */

namespace Vtinnovations\GuardianTypo3\Infrastructure\Manifest;

use Vtinnovations\GuardianTypo3\Infrastructure\Version\ReleaseKeyring;
use Vtinnovations\GuardianTypo3\Infrastructure\Version\SigningKey;

/**
 * Verifies a detached signature over an already-canonicalised message.
 *
 * It owns no key material of its own and makes no policy decision about what the
 * message means: it asks the release key ring for the key the packet claims,
 * checks the signature, and answers yes or no. Every path that cannot produce a
 * definite yes answers no — an empty ring, an unknown or retired identifier, an
 * algorithm outside the allowlist, a signature of the wrong length, a runtime
 * without libsodium, or an exception from the primitive itself.
 *
 * There is no argument, constant or configuration value that makes verification
 * optional.
 */
final class DetachedSignature
{
    public function __construct(private readonly ReleaseKeyring $keyring)
    {
    }

    /**
     * @param string $message      canonical bytes produced by {@see CanonicalForm}
     * @param string $signature    Base64 detached signature as transmitted
     * @param string $purpose      one of the SigningKey::PURPOSE_* selectors
     */
    public function isAuthentic(
        string $message,
        string $signature,
        string $keyId,
        string $algorithm,
        string $purpose,
        int $now,
    ): bool {
        if ($message === '' || $signature === '') {
            return false;
        }
        if (!\function_exists('sodium_crypto_sign_verify_detached')) {
            return false;
        }

        $key = $this->keyring->resolve($keyId, $algorithm, $purpose, $now);
        if ($key === null) {
            return false;
        }

        $raw = base64_decode($signature, true);
        if ($raw === false || strlen($raw) !== SigningKey::expectedSignatureLength()) {
            return false;
        }

        try {
            return sodium_crypto_sign_verify_detached($raw, $message, $key->publicKey);
        } catch (\Throwable) {
            return false;
        }
    }

    /**
     * Verifies a signature that names no key, against every key that could have made it.
     *
     * Used for the record document, which the issuer signs without stating which key it used.
     * Trying each key is not a weakening: a signature still has to verify under a pinned key, and
     * an attacker gains nothing from there being more than one.
     */
    public function isAuthenticForAnyKey(
        string $message,
        string $signature,
        string $algorithm,
        string $purpose,
        int $now,
    ): bool {
        if ($message === '' || $signature === '') {
            return false;
        }
        if (!\function_exists('sodium_crypto_sign_verify_detached')) {
            return false;
        }

        $raw = base64_decode($signature, true);
        if ($raw === false || strlen($raw) !== SigningKey::expectedSignatureLength()) {
            return false;
        }

        foreach ($this->keyring->usableFor($algorithm, $purpose, $now) as $key) {
            try {
                if (sodium_crypto_sign_verify_detached($raw, $message, $key->publicKey)) {
                    return true;
                }
            } catch (\Throwable) {
                // Try the next key; a malformed one must not mask a good one.
            }
        }

        return false;
    }

    /**
     * The diagnostic label for a refusal, so a caller can record *why* without
     * exposing it to the network. It never changes the decision.
     *
     * The lookup is repeated here rather than assumed, because "could not verify"
     * has two very different causes: the key was unavailable, or the key was fine
     * and the signature simply did not match. Reporting the second as a key
     * problem would send an operator hunting for a rotation issue that does not
     * exist.
     */
    public function refusalCategory(
        string $keyId,
        string $algorithm = '',
        string $purpose = SigningKey::PURPOSE_ANY,
        ?int $now = null,
    ): string {
        if (!\function_exists('sodium_crypto_sign_verify_detached')) {
            return 'signature_support_missing';
        }
        if ($algorithm !== '' && $now !== null
            && $this->keyring->resolve($keyId, $algorithm, $purpose, $now) !== null
        ) {
            return 'signature_mismatch';
        }

        return $this->keyring->refusalCategory($keyId);
    }
}
