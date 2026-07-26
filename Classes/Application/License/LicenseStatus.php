<?php

declare(strict_types=1);

/*
 * This file is part of the "Guardian for TYPO3" extension for TYPO3 CMS.
 *
 * @author    V&T Innovations Team
 * @license   LGPL-3.0-or-later
 * @copyright V&T Innovations 2026 - 2028
 */

namespace Vtinnovations\GuardianTypo3\Application\License;

use Vtinnovations\GuardianTypo3\Domain\License\LicenseState;
use Vtinnovations\GuardianTypo3\Domain\License\LicenseTier;

final class LicenseStatus
{
    public function __construct(
        public readonly LicenseState $state,
        public readonly string $status,
        public readonly bool $licensed,
        public readonly bool $pro,
        public readonly bool $cacheStale,
        public readonly string $message = '',
    ) {
    }

    public function tier(): LicenseTier
    {
        return $this->pro ? LicenseTier::Pro : ($this->licensed ? LicenseTier::Free : LicenseTier::None);
    }

    public function keyPreview(): string
    {
        $length = strlen($this->state->key);
        if ($length === 0) {
            return '';
        }
        if ($length <= 8) {
            return str_repeat('•', $length);
        }

        return substr($this->state->key, 0, 4) . str_repeat('•', max(4, $length - 8)) . substr($this->state->key, -4);
    }

    /** @return array<string, bool|int|string|null> */
    public function toPublicArray(): array
    {
        return [
            'status' => $this->status,
            'has_key' => $this->state->key !== '',
            'licensePresent' => $this->state->key !== '',
            'licenseValid' => $this->licensed,
            'canUpdate' => $this->licensed,
            'licensed' => $this->licensed,
            'pro' => $this->pro,
            'plan' => $this->tier()->value,
            'free_available' => $this->state->freeAvailable,
            'expired_free_fallback' => $this->state->hasFreeEntitlement(new \DateTimeImmutable()),
            'key_preview' => $this->keyPreview(),
            'domain' => $this->state->domain,
            'issued_at' => $this->state->issuedAt,
            'starts_at' => $this->state->startsAt,
            'expires_at' => $this->state->expiresAt,
            'lifetime' => $this->state->lifetime,
            'verified_at' => $this->state->verifiedAt ?: null,
            'package' => $this->state->package,
            'features' => $this->state->features,
            'license_version' => $this->state->licenseVersion,
            'schema_version' => LicenseState::SCHEMA_VERSION,
            'signature_present' => $this->state->signature !== '',
            'cache_stale' => $this->cacheStale,
            'message' => $this->message,
        ];
    }
}
