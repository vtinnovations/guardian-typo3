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
 * Turns an internal failure category into something an administrator can act on.
 *
 * Collapsing every failure into one sentence is not secrecy, it is just unhelpful:
 * "the licence could not be verified" reads identically whether the vendor is
 * down, the licence belongs to another domain, or this build is missing the
 * verification key entirely — and only the last of those is fixed by the vendor
 * rather than by the administrator.
 *
 * So each category is mapped to a stable machine code and a sentence that names
 * the *stage* that refused. What is deliberately never included is the material
 * itself: no licence key, no signature, no digest, no canonical bytes, no key
 * bytes, no raw response and no authentication data. A code such as
 * `unknown_signing_key` tells an operator to check key rotation; it tells an
 * attacker nothing they could not learn by reading the shipped source.
 *
 * This applies to the authenticated backend interface only. The public
 * machine-facing endpoint answers every refusal with an identical
 * `{"status":"error"}` and is not routed through here.
 */
final class VerificationDiagnosis
{
    /** The code used when a category has no more specific mapping. */
    public const FALLBACK = 'remote_verification_failed';

    private function __construct(
        public readonly string $code,
        public readonly string $message,
    ) {
    }

    /**
     * The same refusal, worded for the case where a previously confirmed licence
     * is still in force. Losing contact with the vendor is not the same as losing
     * the licence, and the message should not imply that it is.
     */
    public function withRetainedLicence(): self
    {
        return new self(
            $this->code,
            rtrim($this->message, ' ') . ' The licence already stored is still in effect.',
        );
    }

    /**
     * Maps an internal category to its public code and message.
     *
     * Several internal categories intentionally share one public code: an
     * administrator does not need to know which of three ways an envelope was
     * malformed, only that the response was not usable.
     */
    public static function of(string $category): self
    {
        return match ($category) {
            // ── This build cannot verify anything ───────────────────────────
            'signing_key_store_empty' => new self(
                'signing_key_store_empty',
                'Guardian does not contain the approved V-T.ONE verification key. This build cannot verify any licence; please obtain a Guardian release with the current key.',
            ),
            'unknown_signing_key' => new self(
                'unknown_signing_key',
                'The licence was signed with an unrecognised V-T.ONE key. Guardian may need updating to a release that carries the current key.',
            ),
            'signing_key_not_usable' => new self(
                'signing_key_retired',
                'The licence was signed with a V-T.ONE key that is outside its validity period. Please update Guardian.',
            ),
            'signature_support_missing' => new self(
                'signature_support_missing',
                'This server cannot check licence signatures because the PHP sodium extension is unavailable.',
            ),

            // ── The packet was signed, but not correctly ────────────────────
            'record_signature_invalid', 'record_unsigned' => new self(
                'signature_invalid',
                'The V-T.ONE licence signature is invalid.',
            ),
            'payload_digest_mismatch' => new self(
                'integrity_signature_invalid',
                'The licence integrity envelope could not be verified. The licence contents do not match what V-T.ONE signed.',
            ),
            'envelope_signature_invalid', 'envelope_incomplete', 'envelope_product_mismatch', 'envelope_version_mismatch' => new self(
                'integrity_signature_invalid',
                'The licence integrity envelope could not be verified.',
            ),
            'signature_mismatch' => new self(
                'signature_invalid',
                'The V-T.ONE signature did not match the signed content.',
            ),

            // ── The response did not have a usable shape ────────────────────
            'payload_invalid', 'response_malformed', 'response_schema_invalid' => new self(
                'response_schema_invalid',
                'V-T.ONE returned an incomplete or unsupported licence response.',
            ),
            'unexpected_media_type', 'unexpected_status', 'response_too_large' => new self(
                'response_schema_invalid',
                'V-T.ONE returned an unexpected response.',
            ),
            'response_uncorrelated' => new self(
                'request_correlation_failed',
                'The V-T.ONE response did not match the activation request.',
            ),
            'response_clock_skew' => new self(
                'server_clock_skew',
                'This server\'s clock differs too much from V-T.ONE. Please check the system time.',
            ),

            // ── The licence document itself was not acceptable ──────────────
            'record_invalid_dates' => new self(
                'license_dates_invalid',
                'The licence contains invalid validity dates.',
            ),
            'record_invalid_product' => new self(
                'license_product_mismatch',
                'The licence was issued for a different V-T.ONE product.',
            ),
            'record_invalid_domain' => new self(
                'license_domain_invalid',
                'The licence does not name a single valid host.',
            ),
            'record_domains_missing' => new self(
                'license_domains_missing',
                'V-T.ONE returned a licence that does not list the domains it covers. The stored licence was kept; please contact V-T.ONE support.',
            ),
            'record_invalid' => new self(
                'license_document_invalid',
                'The licence document is incomplete or does not match the expected format.',
            ),

            // ── Binding ─────────────────────────────────────────────────────
            'host_binding_mismatch', 'domain_mismatch' => new self(
                'domain_mismatch',
                'The licence is not valid for this exact domain.',
            ),
            'key_binding_mismatch' => new self(
                'license_key_mismatch',
                'V-T.ONE returned a licence for a different licence key.',
            ),
            'host_unresolved' => new self(
                'host_unresolved',
                'Guardian could not establish this installation\'s host name. Check the trusted-hosts pattern in the TYPO3 configuration.',
            ),
            'no_configured_domain' => new self(
                'no_configured_domain',
                'No domain is configured for this installation. Add a TYPO3 site configuration with an absolute base URL, then activate the licence.',
            ),
            'refresh_required' => new self(
                'license_refresh_required',
                'This licence was issued before V-T.ONE began listing the domains it covers. Press Update Licence once to fetch the current licence.',
            ),

            // ── Reachability and local storage ──────────────────────────────
            'transport_failed', 'service_unavailable' => new self(
                'remote_verification_failed',
                'Guardian could not complete verification with V-T.ONE. The service may be temporarily unavailable; the licence already stored is unaffected.',
            ),
            'storage_failed' => new self(
                'license_storage_failed',
                'The licence could not be saved. Please check the permissions of Guardian\'s working directory.',
            ),
            'envelope_unreadable' => new self(
                'license_storage_corrupt',
                'The stored licence could not be read. Please activate the licence again.',
            ),
            'rejected_version' => new self(
                'license_version_older',
                'V-T.ONE returned an older licence than the one already stored; the stored licence was kept.',
            ),

            // ── Vendor decisions ────────────────────────────────────────────
            'denied' => new self(
                'license_key_rejected',
                'This licence key was not accepted for this domain.',
            ),
            'withdrawn' => new self(
                'license_withdrawn',
                'This licence is no longer valid for this domain.',
            ),
            'denied_replacement' => new self(
                'license_key_rejected',
                'The replacement licence key was not accepted; your existing licence was kept.',
            ),

            default => new self(
                self::FALLBACK,
                'Guardian could not complete verification with V-T.ONE.',
            ),
        };
    }
}
