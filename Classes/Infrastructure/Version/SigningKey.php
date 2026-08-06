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
 * One pinned public verification key, together with the constraints that decide
 * when it may be used.
 *
 * Only public material ever reaches this class. A private signing key is never
 * present in a distributed build, so possession of this package cannot be turned
 * into the ability to mint a document, an envelope or a push request.
 *
 * The identifier is a selector, not a credential: it merely chooses which pinned
 * key to try. A key that is unknown, outside its window, structurally wrong or
 * declared for a different purpose is simply unavailable, and the verification it
 * would have performed fails.
 */
final class SigningKey
{
    /** The only signature algorithms this release accepts. */
    public const ALGORITHM_ED25519 = 'ed25519';

    /** Purpose selectors. A key may be pinned for one purpose or for all. */
    public const PURPOSE_DOCUMENT = 'record';
    public const PURPOSE_ENVELOPE = 'envelope';
    public const PURPOSE_REQUEST = 'request';
    public const PURPOSE_ANY = '*';

    /**
     * @param non-empty-string $id
     * @param list<string>     $purposes
     * @param string           $publicKey raw (already decoded) key bytes
     */
    private function __construct(
        public readonly string $id,
        public readonly string $algorithm,
        public readonly string $publicKey,
        public readonly array $purposes,
        public readonly int $activeFrom,
        public readonly ?int $retireAt,
    ) {
    }

    /**
     * Builds a key from its pinned representation, or returns null when the
     * material is unusable. Everything is checked here — a blank identifier,
     * placeholder text, an unlisted algorithm, invalid Base64, a key of the
     * wrong length or an impossible rotation window — so no caller downstream
     * has to decide whether a key is trustworthy.
     *
     * @param list<string> $purposes
     */
    public static function pin(
        string $id,
        string $algorithm,
        string $publicKeyBase64,
        array $purposes = [self::PURPOSE_ANY],
        int $activeFrom = 0,
        ?int $retireAt = null,
    ): ?self {
        $id = trim($id);
        $algorithm = strtolower(trim($algorithm));
        if ($id === '' || strlen($id) > 64 || preg_match('/^[A-Za-z0-9._\-]+$/', $id) !== 1) {
            return null;
        }
        if ($algorithm !== self::ALGORITHM_ED25519) {
            return null;
        }
        if (self::looksLikePlaceholder($publicKeyBase64)) {
            return null;
        }

        $raw = base64_decode(trim($publicKeyBase64), true);
        if ($raw === false || $raw === '' || strlen($raw) !== self::expectedKeyLength()) {
            return null;
        }
        if (rtrim($raw, "\x00") === '') {
            return null; // an all-zero key is never a real verification key
        }

        $allowed = [self::PURPOSE_DOCUMENT, self::PURPOSE_ENVELOPE, self::PURPOSE_REQUEST, self::PURPOSE_ANY];
        $clean = [];
        foreach ($purposes as $purpose) {
            if (!\in_array($purpose, $allowed, true)) {
                return null;
            }
            $clean[] = $purpose;
        }
        if ($clean === []) {
            return null;
        }
        if ($activeFrom < 0 || ($retireAt !== null && $retireAt <= $activeFrom)) {
            return null;
        }

        return new self($id, $algorithm, $raw, $clean, $activeFrom, $retireAt);
    }

    /** Whether the key may be used for this purpose at this instant. */
    public function isUsableFor(string $purpose, int $now): bool
    {
        if (!\in_array($purpose, $this->purposes, true) && !\in_array(self::PURPOSE_ANY, $this->purposes, true)) {
            return false;
        }
        if ($now < $this->activeFrom) {
            return false;
        }

        return $this->retireAt === null || $now < $this->retireAt;
    }

    /**
     * A short, publishable digest of the key.
     *
     * The point of it is comparison through a second channel: the issuer sends
     * the key one way and the fingerprint another, and the two are checked
     * against each other before the key is trusted. Sixteen hex characters of
     * SHA-256 is short enough to read aloud and far too long to collide by
     * accident. It reveals nothing — the key is public material to begin with.
     */
    public function fingerprint(): string
    {
        return substr(hash('sha256', $this->publicKey), 0, 16);
    }

    public static function expectedKeyLength(): int
    {
        return \defined('SODIUM_CRYPTO_SIGN_PUBLICKEYBYTES') ? \SODIUM_CRYPTO_SIGN_PUBLICKEYBYTES : 32;
    }

    public static function expectedSignatureLength(): int
    {
        return \defined('SODIUM_CRYPTO_SIGN_BYTES') ? \SODIUM_CRYPTO_SIGN_BYTES : 64;
    }

    /**
     * Rejects the strings a half-finished configuration typically leaves behind,
     * so a build can never ship with something that merely looks like a key.
     */
    private static function looksLikePlaceholder(string $value): bool
    {
        $candidate = strtolower(trim($value));
        if ($candidate === '') {
            return true;
        }
        foreach (['todo', 'changeme', 'change-me', 'placeholder', 'example', 'xxxx', 'replace', 'dummy', 'sample'] as $marker) {
            if (str_contains($candidate, $marker)) {
                return true;
            }
        }

        return false;
    }
}
