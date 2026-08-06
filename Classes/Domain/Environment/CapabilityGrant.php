<?php

declare(strict_types=1);

/*
 * This file is part of the "Guardian for TYPO3" extension for TYPO3 CMS.
 *
 * @author    V&T Innovations Team
 * @license   LGPL-3.0-or-later
 * @copyright V&T Innovations 2026 - 2028
 */

namespace Vtinnovations\GuardianTypo3\Domain\Environment;

use Vtinnovations\GuardianTypo3\Domain\Configuration\ServiceRecord;
use Vtinnovations\GuardianTypo3\Domain\Configuration\VerificationDiagnosis;

/**
 * The immutable result of evaluating this installation's entitlement.
 *
 * It is shared input for every capability check, not a switch: it carries no
 * setter, and a caller cannot flip it. Individual features still assert their own
 * requirement against {@see tier} at the point where the privileged work actually
 * happens, so removing any single consumer of this object cannot unlock the rest.
 *
 * `state` is a coarse label for the administrator interface and for diagnostics.
 * It never names the specific check that failed and never carries a key, digest
 * or signature.
 */
final class CapabilityGrant
{
    private function __construct(
        public readonly CapabilityTier $tier,
        public readonly string $state,
        public readonly bool $confirmationStale,
        public readonly ?ServiceRecord $record,
        public readonly string $message,
        /** Stable, non-sensitive code naming the stage that refused, or ''. */
        public readonly string $code = '',
        /**
         * The one host that is both configured here and authorised by the vendor,
         * or '' when there is none. Work that runs without a request — a console
         * command, the scheduler, a queue worker — uses this rather than guessing,
         * so it reaches the same answer as an interactive one.
         */
        public readonly string $matchedDomain = '',
    ) {
    }

    public static function withheld(
        string $state,
        string $message = '',
        ?ServiceRecord $record = null,
        string $code = '',
        string $matchedDomain = '',
    ): self {
        return new self(CapabilityTier::None, $state, false, $record, $message, $code, $matchedDomain);
    }

    public static function granted(
        CapabilityTier $tier,
        string $state,
        ServiceRecord $record,
        bool $confirmationStale,
        string $matchedDomain,
        string $message = '',
    ): self {
        return new self($tier, $state, $confirmationStale, $record, $message, '', $matchedDomain);
    }

    public function withMessage(string $message): self
    {
        return new self(
            $this->tier,
            $this->state,
            $this->confirmationStale,
            $this->record,
            $message,
            $this->code,
            $this->matchedDomain,
        );
    }

    /**
     * Attaches the outcome of an attempt that has just been made, so the
     * interface can explain what happened rather than only what is stored now.
     */
    public function withDiagnosis(VerificationDiagnosis $diagnosis): self
    {
        return new self(
            $this->tier,
            $this->state,
            $this->confirmationStale,
            $this->record,
            $diagnosis->message,
            $diagnosis->code,
            $this->matchedDomain,
        );
    }

    public function isLicensed(): bool
    {
        return $this->tier !== CapabilityTier::None;
    }

    public function isPro(): bool
    {
        return $this->tier === CapabilityTier::Pro;
    }

    public function allows(CapabilityTier $required): bool
    {
        return $this->tier->isAtLeast($required);
    }

    /**
     * The administrator-facing projection. It exposes masked, non-sensitive
     * fields only: never the full key, the raw document, the digest, the
     * integrity envelope or any signature.
     *
     * @return array<string, bool|int|string|list<string>|null>
     */
    public function toPublicArray(): array
    {
        $record = $this->record;
        $present = $record !== null;

        return [
            'status' => $this->state,
            'has_key' => $present,
            'licensePresent' => $present,
            'licenseValid' => $this->isLicensed(),
            'canUpdate' => $this->isLicensed(),
            'licensed' => $this->isLicensed(),
            'pro' => $this->isPro(),
            'plan' => $this->tier->value,
            // Both are signed, public facts about the licence: whether the
            // vendor authorised a Free continuation after expiry, and whether
            // that is what is currently in effect.
            'free_available' => $record?->freeAvailable ?? false,
            'expired_free_fallback' => $this->state === 'free_fallback',
            'key_preview' => $record?->maskedKey() ?? '',
            'domain' => $record?->host ?? '',
            // The authorised host set and the vendor's stated allowance. Both are
            // signed, public facts about the licence — no packet material, no
            // digest, no signature.
            'domains' => $record?->authorizedDomains() ?? [],
            'max_domains' => $record?->maxDomains,
            'matched_domain' => $this->matchedDomain,
            'issued_at' => $record?->issuedAt,
            'starts_at' => $record?->startsAt,
            'expires_at' => $record?->expiresAt,
            'lifetime' => $record?->lifetime ?? false,
            'verified_at' => $record !== null && $record->verifiedAt > 0 ? $record->verifiedAt : null,
            'package' => $record?->package ?? '',
            'features' => $record?->features ?? [],
            'license_version' => $record?->version ?? 0,
            'schema_version' => ServiceRecord::SCHEMA_VERSION,
            'signature_present' => $present,
            'cache_stale' => $this->confirmationStale,
            'message' => $this->message,
            // A stable code the administrator can quote in a support request. It
            // names a stage, never any packet material. Deliberately not called
            // "code": the transport layer already uses that key for the outcome
            // of the request itself, and merging would silently drop one of them.
            'verification_code' => $this->code,
        ];
    }
}
