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
 * Immutable outcome of one outbound exchange with the vendor service.
 *
 * A confirmed outcome carries the complete package: the exact document bytes as
 * they were received, the parsed view of them, and the authenticated integrity
 * envelope that must be stored alongside those bytes. The three are only ever
 * handed over together, so the caller cannot persist a document without the
 * envelope that proves it.
 *
 * `category` is a coarse internal label for diagnostics. It never encodes packet
 * contents and is safe to record; `message` is the generic administrator-facing
 * text and likewise contains no key, digest or signature.
 */
final class ProvisioningOutcome
{
    /**
     * @param array<string, mixed> $envelope authenticated integrity envelope
     */
    private function __construct(
        public readonly ProvisioningStatus $status,
        public readonly string $category,
        public readonly string $message,
        public readonly ?ServiceRecord $record = null,
        public readonly ?string $documentBytes = null,
        public readonly array $envelope = [],
    ) {
    }

    /**
     * @param array<string, mixed> $envelope
     */
    public static function confirmed(ServiceRecord $record, string $documentBytes, array $envelope): self
    {
        return new self(
            ProvisioningStatus::Confirmed,
            'confirmed',
            '',
            $record,
            $documentBytes,
            $envelope,
        );
    }

    /** The vendor authenticated the exchange and explicitly refused the key. */
    public static function denied(string $message): self
    {
        return new self(ProvisioningStatus::Denied, 'denied', $message);
    }

    /**
     * The service could not be reached, answered with a server error, or timed
     * out. A previously confirmed record must survive this untouched.
     */
    public static function unreachable(string $category, string $message): self
    {
        return new self(ProvisioningStatus::Unreachable, $category, $message);
    }

    /**
     * The service answered, but the answer failed a local security check
     * (signature, digest, correlation, product or host binding). This is never
     * treated as a denial and never replaces a valid record.
     */
    public static function rejected(string $category, string $message): self
    {
        return new self(ProvisioningStatus::Rejected, $category, $message);
    }

    public function isConfirmed(): bool
    {
        return $this->status === ProvisioningStatus::Confirmed && $this->record !== null;
    }
}
