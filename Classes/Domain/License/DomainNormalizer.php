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
 * Pure, framework-free normalisation of a host name for license/domain matching
 * and telemetry.
 *
 * A raw value taken from an arbitrary request header cannot be trusted: it may
 * carry a scheme, credentials, a port, a path, mixed case, a trailing dot or
 * padding. This collapses such a value to a bare, lower-cased host or returns an
 * empty string when nothing host-like remains. Callers should prefer a host that
 * the framework already validated (e.g. TYPO3's normalized request URI) and pass
 * it here for a final, deterministic clean-up — never build policy decisions on
 * an unnormalised header value.
 */
final class DomainNormalizer
{
    public function normalize(string $candidate): string
    {
        $value = strtolower(trim($candidate));
        if ($value === '') {
            return '';
        }
        // Drop an accidental scheme and any userinfo.
        $value = (string) preg_replace('#^[a-z][a-z0-9+.-]*://#', '', $value);
        if (str_contains($value, '@')) {
            $value = substr($value, strrpos($value, '@') + 1);
        }
        // Cut a path/query/fragment if a full URL slipped through.
        foreach (['/', '?', '#'] as $sep) {
            $pos = strpos($value, $sep);
            if ($pos !== false) {
                $value = substr($value, 0, $pos);
            }
        }
        // Strip a port (but keep IPv6 brackets intact).
        if (!str_contains($value, ']') && str_contains($value, ':')) {
            $value = substr($value, 0, strpos($value, ':') ?: null);
        }
        $value = trim($value, ". \t\n\r\0\x0B");

        // Only [a-z0-9.-] (plus IPv6 brackets/colons) may remain in a host.
        if (preg_match('/^[a-z0-9.\-\[\]:]+$/', $value) !== 1) {
            return '';
        }

        return $value;
    }

    /**
     * Whether $host matches an allowed domain under a simple policy: an exact
     * match, or a subdomain of the allowed domain (e.g. "shop.example.com"
     * matches "example.com"). Comparison is performed on normalised values.
     */
    public function matchesAllowed(string $host, string $allowed): bool
    {
        $host = $this->normalize($host);
        $allowed = $this->normalize($allowed);
        if ($host === '' || $allowed === '') {
            return false;
        }

        return $host === $allowed || str_ends_with($host, '.' . $allowed);
    }
}
