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

/**
 * The installation's host identity: one exact, normalized host name.
 *
 * Normalisation changes REPRESENTATION only, never scope. It lower-cases ASCII,
 * removes a single trailing dot, removes a port, and converts an internationalised
 * name to its ASCII/Punycode form. It never removes a "www" label, never collapses
 * a name to its registrable domain, never resolves aliases or CNAMEs, and never
 * accepts a wildcard.
 *
 * Comparison is EXACT. `example.com`, `www.example.com`, `shop.example.com` and
 * `admin.shop.example.com` are four different identities, and none of them is
 * accepted in place of another. Suffix, substring and pattern matching are
 * deliberately absent from this class, so no caller can accidentally widen a
 * binding — a hostile name such as `malicious-example.com` can never satisfy
 * `example.com`.
 *
 * IP policy: a bare IPv4 literal or a bracketed IPv6 literal is a valid identity
 * and is compared exactly like any other host. No reverse lookup is performed and
 * an address is never considered equivalent to a name that resolves to it.
 */
final class HostIdentity
{
    /** RFC 1035 limits: 253 characters overall, 63 per label. */
    private const MAX_LENGTH = 253;
    private const MAX_LABEL_LENGTH = 63;

    /**
     * Returns the canonical representation of a candidate host, or an empty
     * string when nothing valid remains. An empty result is never a match.
     */
    public static function normalize(string $candidate): string
    {
        $value = trim($candidate);
        if ($value === '' || strlen($value) > 1024) {
            return '';
        }

        // A wildcard is not a host under this protocol. Reject rather than
        // interpret, so "*.example.com" can never be turned into a scope.
        if (str_contains($value, '*')) {
            return '';
        }

        // Tolerate a value that arrived with scheme/userinfo/path attached; these
        // are representation artefacts, not scope.
        $value = (string) preg_replace('#^[A-Za-z][A-Za-z0-9+.\-]*://#', '', $value);
        if (str_contains($value, '@')) {
            $value = substr($value, strrpos($value, '@') + 1);
        }
        foreach (['/', '?', '#'] as $separator) {
            $position = strpos($value, $separator);
            if ($position !== false) {
                $value = substr($value, 0, $position);
            }
        }

        $value = self::stripPort($value);
        // Exactly one trailing dot (the DNS root label) is removed.
        if (str_ends_with($value, '.')) {
            $value = substr($value, 0, -1);
        }
        $value = trim($value);
        if ($value === '') {
            return '';
        }

        if (str_starts_with($value, '[')) {
            return self::normalizeIpv6($value);
        }

        $value = self::toAscii($value);
        if ($value === '' || strlen($value) > self::MAX_LENGTH) {
            return '';
        }

        return self::isValidNameOrIpv4($value) ? $value : '';
    }

    /**
     * Exact identity comparison of two candidate hosts. Both are normalised
     * first; an empty or invalid value on either side never matches. The final
     * comparison is constant-time so it cannot be probed byte by byte.
     */
    public static function equals(string $left, string $right): bool
    {
        $a = self::normalize($left);
        $b = self::normalize($right);
        if ($a === '' || $b === '') {
            return false;
        }

        return hash_equals($a, $b);
    }

    /** Whether a candidate normalises to a usable identity at all. */
    public static function isValid(string $candidate): bool
    {
        return self::normalize($candidate) !== '';
    }

    /**
     * Removes a port suffix. An IPv6 literal keeps everything inside its
     * brackets, so its internal colons are never mistaken for a port separator.
     */
    private static function stripPort(string $value): string
    {
        if (str_starts_with($value, '[')) {
            $close = strpos($value, ']');
            if ($close === false) {
                return $value;
            }
            return substr($value, 0, $close + 1);
        }
        $colon = strpos($value, ':');
        if ($colon === false) {
            return $value;
        }
        // More than one colon in an unbracketed value is a bare IPv6 address,
        // which has no port to remove.
        if (substr_count($value, ':') > 1) {
            return $value;
        }

        return substr($value, 0, $colon);
    }

    /**
     * Converts an internationalised name to its ASCII/Punycode form so the same
     * host always canonicalises to the same bytes. A non-ASCII name on a runtime
     * without `intl` cannot be canonicalised deterministically and is rejected
     * rather than silently compared in its unicode form.
     */
    private static function toAscii(string $value): string
    {
        if (preg_match('/^[\x20-\x7E]*$/', $value) === 1) {
            return strtolower($value);
        }
        if (!\function_exists('idn_to_ascii')) {
            return '';
        }
        $ascii = @idn_to_ascii($value, \IDNA_DEFAULT, \INTL_IDNA_VARIANT_UTS46);

        return \is_string($ascii) && $ascii !== '' ? strtolower($ascii) : '';
    }

    /** Accepts a DNS name (including a Punycode "xn--" label) or an IPv4 literal. */
    private static function isValidNameOrIpv4(string $value): bool
    {
        if (filter_var($value, \FILTER_VALIDATE_IP, \FILTER_FLAG_IPV4) !== false) {
            return true;
        }
        if ($value === '' || str_contains($value, '..')) {
            return false;
        }
        foreach (explode('.', $value) as $label) {
            $length = strlen($label);
            if ($length === 0 || $length > self::MAX_LABEL_LENGTH) {
                return false;
            }
            if (preg_match('/^[a-z0-9]([a-z0-9\-]*[a-z0-9])?$/', $label) !== 1) {
                return false;
            }
        }

        return true;
    }

    /** Normalises a bracketed IPv6 literal to its canonical compressed form. */
    private static function normalizeIpv6(string $value): string
    {
        if (!str_ends_with($value, ']')) {
            return '';
        }
        $inner = substr($value, 1, -1);
        if (filter_var($inner, \FILTER_VALIDATE_IP, \FILTER_FLAG_IPV6) === false) {
            return '';
        }
        $packed = @inet_pton($inner);
        if ($packed === false) {
            return '';
        }
        $canonical = @inet_ntop($packed);

        return \is_string($canonical) ? '[' . strtolower($canonical) . ']' : '';
    }
}
