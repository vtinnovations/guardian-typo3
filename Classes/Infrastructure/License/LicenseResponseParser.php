<?php

declare(strict_types=1);

/*
 * This file is part of the "Guardian for TYPO3" extension for TYPO3 CMS.
 *
 * @author    V&T Innovations Team
 * @license   LGPL-3.0-or-later
 * @copyright V&T Innovations 2026 - 2028
 */

namespace Vtinnovations\GuardianTypo3\Infrastructure\License;

use Vtinnovations\GuardianTypo3\Domain\License\LicenseVerificationResult;

/**
 * Pure translation of a decoded V-T.ONE license response (from either the verify
 * endpoint or the license-updater endpoint) into a {@see LicenseVerificationResult}.
 *
 * It reads every server-supplied license fact — issue date, start date, expiry,
 * lifetime flag, package, features, document version and detached signature —
 * keeping issue/start/verify as three distinct concepts. It NEVER fabricates a
 * date: a field the server omits stays null so the manager can decide the
 * fallback explicitly. Field-name aliases are tolerated so the two endpoints can
 * differ slightly without a second parser.
 */
final class LicenseResponseParser
{
    /**
     * @param array<string, mixed>|mixed $decoded decoded JSON body
     */
    public function parse(mixed $decoded): LicenseVerificationResult
    {
        if (!\is_array($decoded) || !\array_key_exists('valid', $decoded)) {
            return LicenseVerificationResult::unreachable('Malformed response from the license server.');
        }
        if (($decoded['valid'] ?? false) !== true) {
            return LicenseVerificationResult::denied((string) ($decoded['message'] ?? 'License rejected.'));
        }

        $lifetime = $this->readBool($decoded, ['lifetime', 'is_lifetime', 'license_lifetime']);
        $expiresAt = $lifetime ? null : $this->readTimestamp($decoded, ['expires_at', 'license_expires_at', 'expiry']);
        // An explicit zero/absent expiry with no end date is treated as lifetime.
        if (!$lifetime && $expiresAt === null && $this->hasExplicitNoExpiry($decoded)) {
            $lifetime = true;
        }

        return LicenseVerificationResult::valid(
            $expiresAt,
            (string) ($decoded['package'] ?? ''),
            (string) ($decoded['message'] ?? ''),
            $this->readTimestamp($decoded, ['issued_at', 'license_issued_at']),
            $this->readFeatures($decoded),
            $this->readTimestamp($decoded, ['starts_at', 'start_at', 'license_starts_at', 'valid_from']),
            $lifetime,
            (int) ($decoded['license_version'] ?? $decoded['version'] ?? 0),
            \is_string($decoded['signature'] ?? null) ? $decoded['signature'] : '',
            $this->readBool($decoded, ['free_available', 'license_free_available']),
        );
    }

    /**
     * @param array<string, mixed> $data
     * @param list<string> $keys
     */
    private function readTimestamp(array $data, array $keys): ?int
    {
        foreach ($keys as $key) {
            if (isset($data[$key]) && $data[$key] !== '' && $data[$key] !== false) {
                $value = (int) $data[$key];
                if ($value > 0) {
                    return $value;
                }
            }
        }

        return null;
    }

    /**
     * @param array<string, mixed> $data
     * @param list<string> $keys
     */
    private function readBool(array $data, array $keys): bool
    {
        foreach ($keys as $key) {
            if (\array_key_exists($key, $data)) {
                $value = $data[$key];
                if (\is_bool($value)) {
                    return $value;
                }
                if (\is_int($value)) {
                    return $value === 1;
                }
                if (\is_string($value)) {
                    return \in_array(strtolower(trim($value)), ['1', 'true', 'yes'], true);
                }
            }
        }

        return false;
    }

    /**
     * @param array<string, mixed> $data
     * @return list<string>
     */
    private function readFeatures(array $data): array
    {
        $raw = $data['features'] ?? $data['entitlements'] ?? $data['license_features'] ?? null;
        if (!\is_array($raw)) {
            return [];
        }
        $features = [];
        foreach ($raw as $feature) {
            if (\is_string($feature) && $feature !== '') {
                $features[] = $feature;
            }
        }

        return $features;
    }

    /**
     * A server may signal "no expiry" with an explicit null expiry field present
     * in the document (as opposed to simply omitting it).
     *
     * @param array<string, mixed> $data
     */
    private function hasExplicitNoExpiry(array $data): bool
    {
        foreach (['expires_at', 'license_expires_at', 'expiry'] as $key) {
            if (\array_key_exists($key, $data) && ($data[$key] === null || $data[$key] === 0 || $data[$key] === '0')) {
                return true;
            }
        }

        return false;
    }
}
