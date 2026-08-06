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

use Vtinnovations\GuardianTypo3\Domain\Configuration\ServiceRecord;
use Vtinnovations\GuardianTypo3\Infrastructure\Version\SigningKey;

/**
 * Opens a complete, sealed record package and decides whether it may be trusted.
 *
 * Every packet that can change stored state — a first activation, an
 * administrator refresh and a vendor-initiated push alike — passes through here,
 * so all three obtain exactly the same guarantees in exactly the same order:
 *
 *   1. the envelope is structurally complete;
 *   2. the envelope's own signature verifies, which is what makes the digest it
 *      carries meaningful in the first place;
 *   3. the payload decodes under strict Base64 to a bounded byte string;
 *   4. the digest of those exact bytes matches the envelope, compared in
 *      constant time;
 *   5. only then are the bytes parsed — and the parse result is never re-encoded
 *      and re-checked, because re-serialisation would produce different bytes;
 *   6. the document's own signature verifies over the canonical field order;
 *   7. the document satisfies every protocol invariant;
 *   8. the envelope and the document agree about product and version.
 *
 * The digest alone is never sufficient: it is a byte-level tripwire carried
 * inside an authenticated envelope, not evidence of origin. A caller that
 * receives a refusal must leave whatever is already stored exactly as it is.
 */
final class SealedPackage
{
    /** A record document is small; anything larger is not one. */
    public const MAX_DOCUMENT_BYTES = 65536;

    public function __construct(
        private readonly CanonicalForm $canonical,
        private readonly DetachedSignature $signature,
    ) {
    }

    /**
     * Opens a package as it arrives on the wire.
     *
     * @param array<string, mixed> $envelope
     */
    public function open(string $payloadBase64, array $envelope, int $now): SealedPackageResult
    {
        // Strict decode. PHP's own strict mode still tolerates whitespace inside
        // the payload, so the alphabet, the length and the padding are checked
        // here first: the transmitted form must be the one canonical encoding of
        // the bytes, with no room for a variant that decodes the same.
        if ($payloadBase64 === '' || strlen($payloadBase64) > self::MAX_DOCUMENT_BYTES * 2) {
            return SealedPackageResult::refused('payload_invalid');
        }
        if (preg_match('#^[A-Za-z0-9+/]+={0,2}$#', $payloadBase64) !== 1 || strlen($payloadBase64) % 4 !== 0) {
            return SealedPackageResult::refused('payload_invalid');
        }
        $bytes = base64_decode($payloadBase64, true);
        if ($bytes === false || base64_encode($bytes) !== $payloadBase64) {
            return SealedPackageResult::refused('payload_invalid');
        }

        return $this->openStored($bytes, $envelope, $now);
    }

    /**
     * Opens a package that is already held as raw bytes — the form it takes once
     * it has been written to disk. Re-running the full check on every read is
     * what makes a hand-edited document or a swapped envelope fail immediately.
     *
     * @param array<string, mixed> $envelope
     */
    public function openStored(string $bytes, array $envelope, int $now): SealedPackageResult
    {
        $keyId = $this->text($envelope, 'key_id');
        $algorithm = $this->text($envelope, 'signature_algorithm');
        $envelopeSignature = $this->text($envelope, 'signature');
        $digest = strtolower($this->text($envelope, 'license_md5'));
        $version = $envelope['license_version'] ?? null;

        if ($keyId === '' || $algorithm === '' || $envelopeSignature === '' || !\is_int($version) || $version < 1) {
            return SealedPackageResult::refused('envelope_incomplete');
        }
        if (preg_match('/^[0-9a-f]{32}$/', $digest) !== 1) {
            return SealedPackageResult::refused('envelope_incomplete');
        }
        if (($envelope['project'] ?? null) !== ServiceRecord::PROJECT
            || ($envelope['project_slug'] ?? null) !== ServiceRecord::PROJECT_SLUG
        ) {
            return SealedPackageResult::refused('envelope_product_mismatch');
        }

        // 2. Authenticate the envelope before believing anything it says.
        $envelopeMessage = $this->canonical->envelope($envelope);
        if (!$this->signature->isAuthentic(
            $envelopeMessage,
            $envelopeSignature,
            $keyId,
            $algorithm,
            SigningKey::PURPOSE_ENVELOPE,
            $now,
        )) {
            $category = $this->signature->refusalCategory(
                $keyId,
                $algorithm,
                SigningKey::PURPOSE_ENVELOPE,
                $now,
            );

            return SealedPackageResult::refused(
                $category === 'signature_mismatch' ? 'envelope_signature_invalid' : $category
            );
        }

        // 3. The bytes must be present and bounded before anything reads them.
        if ($bytes === '' || strlen($bytes) > self::MAX_DOCUMENT_BYTES) {
            return SealedPackageResult::refused('payload_invalid');
        }

        // 4. Exact-byte tripwire. A single changed byte — including whitespace —
        //    ends the exchange here.
        if (!hash_equals($digest, hash('md5', $bytes))) {
            return SealedPackageResult::refused('payload_digest_mismatch');
        }

        // 5. Parse the verified bytes. They are never re-encoded afterwards.
        //    An integer too large for the platform becomes a string here and is
        //    therefore rejected by the document rules rather than rounded.
        try {
            $document = json_decode($bytes, true, 32, \JSON_THROW_ON_ERROR | \JSON_BIGINT_AS_STRING);
        } catch (\JsonException) {
            return SealedPackageResult::refused('payload_invalid');
        }
        if (!\is_array($document) || array_is_list($document)) {
            return SealedPackageResult::refused('payload_invalid');
        }

        // 6. The document carries its own signature over the canonical fields,
        //    independent of how it happens to be laid out on the wire.
        $documentSignature = $this->text($document, 'signature');
        if ($documentSignature === '') {
            return SealedPackageResult::refused('record_unsigned');
        }
        // The record carries no key id of its own — only the envelope and the push
        // request name one. Verifying against every key held is what keeps a
        // record signed before a rotation working after it.
        $documentMessage = $this->canonical->document($document);
        if (!$this->signature->isAuthenticForAnyKey(
            $documentMessage,
            $documentSignature,
            $algorithm,
            SigningKey::PURPOSE_DOCUMENT,
            $now,
        )) {
            return SealedPackageResult::refused('record_signature_invalid');
        }

        // 7. Protocol invariants: product, exact host, dates, lifetime rule,
        //    package, features, status and version.
        $record = ServiceRecord::fromDocument($document, $reason);
        if ($record === null) {
            // Naming the family that failed lets the administrator be told
            // whether the dates, the product or the host were the problem.
            return SealedPackageResult::refused(match ($reason) {
                'dates' => 'record_invalid_dates',
                'product' => 'record_invalid_product',
                'domain' => 'record_invalid_domain',
                default => 'record_invalid',
            });
        }

        // 8. The two authenticated statements must describe the same thing.
        if ($record->version !== $version) {
            return SealedPackageResult::refused('envelope_version_mismatch');
        }

        return SealedPackageResult::opened($record, $bytes, $envelope);
    }

    /**
     * @param array<string, mixed> $data
     */
    private function text(array $data, string $field): string
    {
        $value = $data[$field] ?? null;

        return \is_string($value) ? trim($value) : '';
    }
}
