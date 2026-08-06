<?php

declare(strict_types=1);

/*
 * This file is part of the "Guardian for TYPO3" extension for TYPO3 CMS.
 *
 * @author    V&T Innovations Team
 * @license   LGPL-3.0-or-later
 * @copyright V&T Innovations 2026 - 2028
 */

namespace Vtinnovations\GuardianTypo3\Domain\Configuration;

/**
 * Immutable outcome of an inbound, vendor-initiated record push.
 *
 * It carries exactly what the public endpoint is allowed to disclose: the HTTP
 * status, the protocol status word, the correlating request identifier and — for
 * an accepted or already-processed request — the resulting document version. The
 * `category` is an internal diagnostic label that is never sent to the caller,
 * so an unauthenticated client cannot learn which specific check refused it.
 */
final class RecordIntakeOutcome
{
    private function __construct(
        public readonly int $httpStatus,
        public readonly string $status,
        public readonly string $category,
        public readonly string $requestId = '',
        public readonly ?int $version = null,
    ) {
    }

    public function isSuccess(): bool
    {
        return $this->httpStatus >= 200 && $this->httpStatus < 300;
    }

    public static function updated(string $requestId, int $version): self
    {
        return new self(200, 'updated', 'updated', $requestId, $version);
    }

    public static function alreadyProcessed(string $requestId, ?int $version): self
    {
        return new self(200, 'already_processed', 'already_processed', $requestId, $version);
    }

    /** A malformed request body that never reached authentication. */
    public static function malformed(string $category = 'malformed'): self
    {
        return new self(400, 'error', $category);
    }

    /**
     * Every authentication failure — missing, stale, replayed, unknown-key,
     * algorithm-mismatched or invalid signature — collapses into one generic
     * answer so the endpoint cannot be used as a verification oracle.
     */
    public static function unauthenticated(string $category): self
    {
        return new self(401, 'error', $category);
    }

    /** An authenticated request that is not authorised for this installation. */
    public static function refused(string $category): self
    {
        return new self(403, 'error', $category);
    }

    /** An authenticated request that conflicts with the stored state. */
    public static function conflict(string $category): self
    {
        return new self(409, 'error', $category);
    }

    public static function methodNotAllowed(): self
    {
        return new self(405, 'error', 'method_not_allowed');
    }

    public static function payloadTooLarge(): self
    {
        return new self(413, 'error', 'payload_too_large');
    }

    public static function unsupportedMediaType(): self
    {
        return new self(415, 'error', 'unsupported_media_type');
    }

    /** Activation was attempted and could not be completed; state was restored. */
    public static function rolledBack(string $category = 'rolled_back'): self
    {
        return new self(500, 'error', $category);
    }
}
