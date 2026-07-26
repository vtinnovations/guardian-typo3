<?php

declare(strict_types=1);

/*
 * This file is part of the "Guardian for TYPO3" extension for TYPO3 CMS.
 *
 * @author    V&T Innovations Team
 * @license   LGPL-3.0-or-later
 * @copyright V&T Innovations 2026 - 2028
 */

namespace Vtinnovations\GuardianTypo3\Domain\License;

/**
 * Immutable interpretation of the locally cached license verification result and
 * the single authoritative in-memory representation of the versioned license
 * store (`var/guardian/license.json`, schema_version 2).
 *
 * Timestamps are modelled as nullable integers internally (null = "not set") for
 * ergonomic, unambiguous logic; the canonical on-disk schema serialises them as
 * `0` sentinels plus an explicit `license_lifetime` boolean, so a license with no
 * expiry is stored as `license_expires_at: 0, license_lifetime: true` — never a
 * bare `null` whose meaning is ambiguous. {@see fromArray()} reads both the v2
 * schema and legacy (pre-v2) files, migrating the latter losslessly.
 *
 * Three date concepts are kept strictly separate:
 *   - license_issued_at   — when the license was created/issued by the server;
 *   - license_starts_at   — when the license becomes active (validity start);
 *   - license_verified_at — when THIS installation last confirmed it (never the
 *                           issue or start date — the historical bug this fixes).
 *
 * Nothing here talks to the network, the filesystem or the clock: the caller
 * supplies "now".
 */
final class LicenseState
{
    /** Canonical store schema version. */
    public const SCHEMA_VERSION = 2;

    /** Stable project identity embedded in the store. */
    public const PROJECT = 'Guardian';
    public const PROJECT_SLUG = 'guardian';

    /** Default trust window after the last successful verification: 7 days. */
    public const DEFAULT_GRACE_SECONDS = 7 * 86400;

    /**
     * @param list<string> $features server-confirmed feature/entitlement flags
     */
    public function __construct(
        public readonly string $key,
        /** Unix timestamp of the last successful verification, 0 = never. */
        public readonly int $verifiedAt,
        /** Hard expiry from the server, or null for a lifetime/open-ended license. */
        public readonly ?int $expiresAt,
        public readonly string $domain,
        public readonly string $package,
        public readonly LicenseValidationStatus $validationStatus,
        /** License issue date from the server, or null when not provided. */
        public readonly ?int $issuedAt = null,
        public readonly array $features = [],
        public readonly bool $freeAvailable = false,
        /** License start/activation date from the server, or null when open-ended. */
        public readonly ?int $startsAt = null,
        /** Explicit "no expiry" flag from the server (authoritative over expiry). */
        public readonly bool $lifetime = false,
        /** Server-side license document revision, 0 = unknown. */
        public readonly int $licenseVersion = 0,
        /** Detached signature over the canonical document, or '' when unsigned. */
        public readonly string $signature = '',
    ) {
    }

    public static function unlicensed(): self
    {
        return new self('', 0, null, '', '', LicenseValidationStatus::None, null, []);
    }

    /**
     * Reads the canonical v2 schema and losslessly migrates legacy (pre-v2)
     * files. Legacy files stored a bare `null` expiry with no lifetime flag; on a
     * verified key that is migrated to an explicit lifetime license.
     *
     * @param array<string, mixed> $data
     */
    public static function fromArray(array $data): self
    {
        $verifiedAt = (int) ($data['license_verified_at'] ?? 0);
        $expiresAt = self::readTimestamp($data['license_expires_at'] ?? null);
        $startsAt = self::readTimestamp($data['license_starts_at'] ?? null);
        $issuedAt = self::readTimestamp($data['license_issued_at'] ?? null);

        // Lifetime: honour an explicit flag; otherwise migrate a legacy verified
        // key with no stored expiry to an explicit lifetime license.
        if (\array_key_exists('license_lifetime', $data)) {
            $lifetime = (bool) $data['license_lifetime'];
        } else {
            $lifetime = $expiresAt === null && $verifiedAt > 0;
        }

        $features = [];
        if (isset($data['license_features']) && \is_array($data['license_features'])) {
            foreach ($data['license_features'] as $feature) {
                if (\is_string($feature) && $feature !== '') {
                    $features[] = $feature;
                }
            }
        }

        return new self(
            key: trim((string) ($data['license_key'] ?? '')),
            verifiedAt: $verifiedAt,
            expiresAt: $expiresAt,
            domain: trim((string) ($data['license_domain'] ?? '')),
            package: strtolower(trim((string) ($data['license_package'] ?? ''))),
            validationStatus: LicenseValidationStatus::tryFrom((string) ($data['validation_status'] ?? ''))
                ?? ($verifiedAt > 0 ? LicenseValidationStatus::Valid : LicenseValidationStatus::None),
            issuedAt: $issuedAt,
            features: $features,
            freeAvailable: (bool) ($data['free_available'] ?? $data['license_free_available'] ?? false),
            startsAt: $startsAt,
            lifetime: $lifetime,
            licenseVersion: (int) ($data['license_version'] ?? 0),
            signature: \is_string($data['signature'] ?? null) ? $data['signature'] : '',
        );
    }

    /** Normalise a stored timestamp: null/empty/0 → null, otherwise a positive int. */
    private static function readTimestamp(mixed $raw): ?int
    {
        if ($raw === null || $raw === '' || $raw === false) {
            return null;
        }
        $value = (int) $raw;

        return $value > 0 ? $value : null;
    }

    /**
     * True when a key exists, was verified at least once, has reached its stored
     * start date and has not passed its stored expiry. Validity is derived purely
     * from the locally stored dates — no network call is made — so a verified
     * license keeps working offline until it genuinely expires (or forever for a
     * lifetime license). Both Free and Pro keys count as "licensed"; use
     * {@see self::isPro()} to gate paid features.
     *
     * $graceSeconds is retained for backward-compatible call sites but no longer
     * shortens validity: stored dates are authoritative.
     */
    public function isLicensed(\DateTimeImmutable $now, int $graceSeconds = self::DEFAULT_GRACE_SECONDS): bool
    {
        if ($this->key === '' || $this->verifiedAt <= 0) {
            return false;
        }

        $nowTs = $now->getTimestamp();

        if ($this->verifiedAt > $nowTs) {
            return false; // forged/future verification timestamp
        }
        if (!$this->hasStarted($now)) {
            return false; // start date not yet reached
        }
        if ($this->isExpired($now)) {
            return false; // past the stored expiry (unless lifetime)
        }

        return true;
    }

    /**
     * The effective activation date: the explicit start date when present, else
     * the issue date (legacy files stored only the issue date as the start).
     */
    public function effectiveStart(): ?int
    {
        return $this->startsAt ?? $this->issuedAt;
    }

    /** Whether the effective start date (if any) has been reached. */
    public function hasStarted(\DateTimeImmutable $now): bool
    {
        $start = $this->effectiveStart();

        return $start === null || $now->getTimestamp() >= $start;
    }

    public function isPro(\DateTimeImmutable $now, int $graceSeconds = self::DEFAULT_GRACE_SECONDS): bool
    {
        return LicenseTier::fromPackage($this->package) === LicenseTier::Pro && $this->isLicensed($now, $graceSeconds);
    }

    public function hasFreeEntitlement(\DateTimeImmutable $now): bool
    {
        if ($this->package !== 'pro' || !$this->freeAvailable || $this->key === '' || $this->verifiedAt <= 0) {
            return false;
        }
        return $this->isExpired($now);
    }

    /**
     * Effective tier once the start/expiry rules are applied: an expired,
     * not-yet-started or never-verified key collapses to {@see LicenseTier::None}.
     */
    public function effectiveTier(\DateTimeImmutable $now, int $graceSeconds = self::DEFAULT_GRACE_SECONDS): LicenseTier
    {
        if (!$this->isLicensed($now, $graceSeconds) && !$this->hasFreeEntitlement($now)) {
            return LicenseTier::None;
        }

        $tier = LicenseTier::fromPackage($this->package);

        return $tier === LicenseTier::None ? LicenseTier::Free : $tier;
    }

    /**
     * A license is expired only when it is NOT a lifetime license and a stored
     * expiry is in the past. A lifetime license never expires.
     */
    public function isExpired(\DateTimeImmutable $now): bool
    {
        if ($this->lifetime) {
            return false;
        }

        return $this->expiresAt !== null && $this->expiresAt < $now->getTimestamp();
    }

    public function isCacheStale(\DateTimeImmutable $now, int $maxAgeSeconds = 86400): bool
    {
        return $this->verifiedAt > 0 && ($now->getTimestamp() - $this->verifiedAt) > $maxAgeSeconds;
    }

    /**
     * The authoritative, versioned canonical store representation. Timestamps are
     * serialised as `0` sentinels and paired with the explicit `license_lifetime`
     * flag; the identity fields (schema/project/slug) are always present.
     *
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'schema_version' => self::SCHEMA_VERSION,
            'project' => self::PROJECT,
            'project_slug' => self::PROJECT_SLUG,
            'license_key' => $this->key,
            'license_domain' => $this->domain,
            'license_package' => $this->package,
            'license_features' => array_values($this->features),
            'license_version' => $this->licenseVersion,
            'license_issued_at' => $this->issuedAt ?? 0,
            'license_starts_at' => $this->startsAt ?? 0,
            'license_expires_at' => $this->expiresAt ?? 0,
            'license_lifetime' => $this->lifetime,
            'license_verified_at' => $this->verifiedAt,
            'free_available' => $this->freeAvailable,
            'signature' => $this->signature,
            'validation_status' => $this->validationStatus->value,
        ];
    }
}
