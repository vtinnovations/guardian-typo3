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
 * Structured outcome of a remote verification / license-update call. Carries the
 * full set of server-supplied license facts so the manager can persist the
 * canonical store without inventing any date.
 */
final class LicenseVerificationResult
{
    /**
     * @param list<string> $features server-returned feature/entitlement flags
     */
    public function __construct(
        public readonly LicenseVerificationStatus $status,
        public readonly ?int $expiresAt,
        public readonly string $package,
        public readonly string $message,
        public readonly ?int $issuedAt = null,
        public readonly array $features = [],
        public readonly ?int $startsAt = null,
        public readonly bool $lifetime = false,
        public readonly int $licenseVersion = 0,
        public readonly string $signature = '',
        public readonly bool $freeAvailable = false,
    ) {
    }

    /**
     * @param list<string> $features
     */
    public static function valid(
        ?int $expiresAt,
        string $package,
        string $message = '',
        ?int $issuedAt = null,
        array $features = [],
        ?int $startsAt = null,
        bool $lifetime = false,
        int $licenseVersion = 0,
        string $signature = '',
        bool $freeAvailable = false,
    ): self {
        return new self(
            LicenseVerificationStatus::Valid,
            $expiresAt,
            strtolower(trim($package)),
            $message,
            $issuedAt,
            array_values($features),
            $startsAt,
            $lifetime,
            $licenseVersion,
            $signature,
            $freeAvailable,
        );
    }

    public static function denied(string $message = ''): self
    {
        return new self(LicenseVerificationStatus::Denied, null, '', $message);
    }

    public static function unreachable(string $message = ''): self
    {
        return new self(LicenseVerificationStatus::Unreachable, null, '', $message);
    }
}
