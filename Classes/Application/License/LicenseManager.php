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

use Vtinnovations\GuardianTypo3\Application\Contract\LicenseStateRepositoryInterface;
use Vtinnovations\GuardianTypo3\Application\Contract\LicenseUpdaterInterface;
use Vtinnovations\GuardianTypo3\Application\Contract\LicenseVerifierInterface;
use Vtinnovations\GuardianTypo3\Domain\Clock\ClockInterface;
use Vtinnovations\GuardianTypo3\Domain\License\DomainNormalizer;
use Vtinnovations\GuardianTypo3\Domain\License\LicenseResult;
use Vtinnovations\GuardianTypo3\Domain\License\LicenseState;
use Vtinnovations\GuardianTypo3\Domain\License\LicenseValidationStatus;
use Vtinnovations\GuardianTypo3\Domain\License\LicenseVerificationResult;
use Vtinnovations\GuardianTypo3\Domain\License\LicenseVerificationStatus;
use Vtinnovations\GuardianTypo3\Infrastructure\License\InvocationSignal;
use Vtinnovations\GuardianTypo3\Infrastructure\License\LicenseStoreReader;
use Vtinnovations\GuardianTypo3\Infrastructure\License\SignatureSentinel;
use Vtinnovations\GuardianTypo3\Infrastructure\License\StoreIntegritySentinel;

final class LicenseManager
{
    /** The stable project identifier transmitted by the invocation signal. */
    private const PROJECT = LicenseState::PROJECT;

    /**
     * The hardening collaborators are optional so the established constructor
     * (repository, verifier, clock) stays backward compatible; dependency
     * injection supplies them in production. The updater drives the authoritative
     * "Update License" refresh; when absent, the verifier is used for both.
     */
    public function __construct(
        private readonly LicenseStateRepositoryInterface $repository,
        private readonly LicenseVerifierInterface $verifier,
        private readonly ClockInterface $clock,
        private readonly ?StoreIntegritySentinel $integrity = null,
        private readonly ?SignatureSentinel $signature = null,
        private readonly ?LicenseStoreReader $store = null,
        private readonly ?InvocationSignal $signal = null,
        private readonly ?DomainNormalizer $domainNormalizer = null,
        private readonly ?LicenseUpdaterInterface $updater = null,
    ) {
    }

    public function currentStatus(string $message = ''): LicenseStatus
    {
        // Emit the operational invocation signal at most once per request.
        $this->emitSignal();

        $state = $this->repository->load();
        $now = $this->clock->now();

        // First-level, raw-byte tamper indicator. Fails closed only when pinned.
        if ($this->integrity !== null && !$this->integrity->intact()) {
            return new LicenseStatus(
                $state,
                'invalid',
                false,
                false,
                $state->isCacheStale($now),
                $message !== '' ? $message : 'The license could not be verified. Please re-activate your license.',
            );
        }

        // Optional asymmetric signature over the stored canonical document. Only
        // enforced when a signature is present AND a verification key is embedded
        // (otherwise "not applicable" and skipped). A present-but-invalid
        // signature fails closed.
        if ($this->signatureBroken()) {
            return new LicenseStatus(
                $state,
                'invalid',
                false,
                false,
                $state->isCacheStale($now),
                $message !== '' ? $message : 'The license signature could not be verified. Please re-activate your license.',
            );
        }

        // Local domain binding: reject a stored license whose licensed domain does
        // not match the normalized current host (a copied license.json used on
        // another domain). Purely local — no remote call. Skipped when the host
        // cannot be determined (CLI/worker) so it never blocks background tasks.
        if ($this->domainMismatch($state)) {
            return new LicenseStatus(
                $state,
                'invalid',
                false,
                false,
                $state->isCacheStale($now),
                $message !== '' ? $message : 'This license is not valid for the current domain.',
            );
        }

        $licensed = $state->isLicensed($now) || $state->hasFreeEntitlement($now);
        $status = match (true) {
            $state->key === '' => 'none',
            $state->isExpired($now) => 'expired',
            !$state->hasStarted($now) => 'not_started',
            $state->validationStatus === LicenseValidationStatus::Unreachable => 'unreachable',
            $state->hasFreeEntitlement($now) => 'free_fallback',
            $licensed && $state->isPro($now) => 'pro',
            $licensed => 'free',
            default => 'invalid',
        };

        return new LicenseStatus($state, $status, $licensed, $state->isPro($now), $state->isCacheStale($now), $message);
    }

    /**
     * True when a signature layer is in play for the stored document AND the
     * signature does not verify. Absent/not-applicable signatures never block.
     */
    private function signatureBroken(): bool
    {
        if ($this->signature === null || $this->store === null) {
            return false;
        }
        $payload = $this->store->decoded();
        if ($payload === null || !$this->signature->applies($payload)) {
            return false;
        }

        return !$this->signature->verified($payload);
    }

    /**
     * True only when a licensed domain is stored, a current host is determinable
     * (web request), and neither is a match/subdomain of the other. Bidirectional
     * so "example.com" ↔ "www.example.com" is always accepted.
     */
    private function domainMismatch(LicenseState $state): bool
    {
        if ($this->domainNormalizer === null || $state->domain === '' || \PHP_SAPI === 'cli') {
            return false;
        }
        $host = $this->domainNormalizer->normalize((string) ($_SERVER['HTTP_HOST'] ?? $_SERVER['SERVER_NAME'] ?? ''));
        if ($host === '') {
            return false; // host not determinable → do not block
        }

        return !$this->domainNormalizer->matchesAllowed($host, $state->domain)
            && !$this->domainNormalizer->matchesAllowed($state->domain, $host);
    }

    /**
     * Layered evaluation combining the raw-store integrity check, the optional
     * asymmetric signature check and the effective entitlement into a single
     * structured result. This is the authoritative "single license result" for
     * callers that need the individual layer outcomes; {@see currentStatus()}
     * remains the established entry point for the existing UI/API.
     */
    public function evaluate(): LicenseResult
    {
        $integrityValid = $this->integrity === null || $this->integrity->intact();
        if (!$integrityValid) {
            return LicenseResult::integrityFailure();
        }

        $signatureValid = true;
        if ($this->signature !== null && $this->store !== null) {
            $payload = $this->store->decoded();
            if ($payload !== null) {
                $signatureValid = $this->signature->verified($payload);
            }
        }

        $statusView = $this->currentStatus();
        $licenseValid = $signatureValid && $statusView->licensed;

        return new LicenseResult($integrityValid, $signatureValid, $licenseValid, $statusView->status);
    }

    private function emitSignal(): void
    {
        if ($this->signal === null) {
            return;
        }
        $normalizer = $this->domainNormalizer ?? new DomainNormalizer();
        $host = (string) ($_SERVER['HTTP_HOST'] ?? $_SERVER['SERVER_NAME'] ?? '');
        $domain = $normalizer->normalize($host);
        if ($domain === '') {
            $domain = $normalizer->normalize($this->repository->load()->domain);
        }
        $this->signal->arm(self::PROJECT, $domain);
    }

    /**
     * Activates a new license OR performs an "Update License" refresh of the
     * stored one. The authoritative license document is fetched from the license
     * updater when available (falling back to the verify endpoint), so the stored
     * start/issue/expiry dates and signature always come from the server — never
     * fabricated from the local verification time.
     *
     * On a FAILED update — denied or the server unreachable — a previously VALID
     * stored license is preserved rather than overwritten, so a mistyped
     * replacement key can never revoke a working license.
     */
    public function activate(string $key, string $domain): LicenseStatus
    {
        $key = trim($key);
        $domain = strtolower(trim($domain));
        $existing = $this->repository->load();
        $hasValidExisting = $existing->isLicensed($this->clock->now());

        // Empty key + a stored license → reuse the stored key SERVER-SIDE (the
        // browser never sends or receives it) to re-verify/refresh the active
        // license without the administrator re-typing it.
        if ($key === '' && $existing->key !== '') {
            $key = $existing->key;
        }

        if ($key === '' || strlen($key) > 190 || $domain === '') {
            if (!$hasValidExisting) {
                $this->repository->save(new LicenseState($key, 0, null, '', '', LicenseValidationStatus::Invalid));
            }
            return $this->currentStatus('No valid license key or host was provided.');
        }

        $result = $this->fetchAuthoritative($key, $domain);
        if ($result->status === LicenseVerificationStatus::Valid) {
            // A fresh activation is authoritative for its own dates — no inheritance
            // from any previously stored (possibly different) license.
            $this->storeVerified($result, $key, $domain, null);

            return $this->currentStatus($this->redactKey($result->message, $key));
        }

        // Verification failed: preserve an existing valid license on update.
        if ($hasValidExisting) {
            return $this->currentStatus('The replacement license could not be verified — your existing license was kept.');
        }

        $this->repository->save(new LicenseState(
            $key,
            0,
            null,
            '',
            '',
            $result->status === LicenseVerificationStatus::Unreachable
                ? LicenseValidationStatus::Unreachable
                : LicenseValidationStatus::Invalid
        ));

        return $this->currentStatus($this->redactKey($result->message, $key));
    }

    public function refresh(string $fallbackDomain, string $requiredPackage = ''): LicenseStatus
    {
        $state = $this->repository->load();
        if ($state->key === '') {
            return $this->currentStatus();
        }

        $domain = $state->domain !== '' ? $state->domain : strtolower(trim($fallbackDomain));
        $result = $this->verifier->verify($state->key, $domain, $requiredPackage);
        if ($result->status === LicenseVerificationStatus::Valid) {
            // A periodic re-verify may return a minimal payload; unspecified dates
            // fall back to the stored ones — never to the current time.
            $this->storeVerified($result, $state->key, $domain, $state);
        } elseif ($result->status === LicenseVerificationStatus::Denied) {
            $this->repository->save(new LicenseState($state->key, 0, null, '', '', LicenseValidationStatus::Invalid, null, []));
        } else {
            $this->repository->save(new LicenseState(
                $state->key,
                $state->verifiedAt,
                $state->expiresAt,
                $state->domain,
                $state->package,
                LicenseValidationStatus::Unreachable,
                $state->issuedAt,
                $state->features,
                $state->freeAvailable,
                $state->startsAt,
                $state->lifetime,
                $state->licenseVersion,
                $state->signature,
            ));
        }

        return $this->currentStatus($this->redactKey($result->message, $state->key));
    }

    public function clear(): LicenseStatus
    {
        $this->repository->clear();

        return $this->currentStatus();
    }

    /**
     * Fetch the authoritative license document: the license updater when
     * configured (the "Update License" endpoint), otherwise the verify endpoint.
     */
    private function fetchAuthoritative(string $key, string $domain): LicenseVerificationResult
    {
        return $this->updater !== null
            ? $this->updater->update($key, $domain)
            : $this->verifier->verify($key, $domain);
    }

    /**
     * Persist a verified license using ONLY server-supplied dates. Every date is
     * taken from the result; where the server omits one it falls back to the
     * inherited (previously stored) value on a refresh, or to null on a fresh
     * activation — never to the local verification time.
     */
    private function storeVerified(LicenseVerificationResult $result, string $key, string $domain, ?LicenseState $inheritFrom): void
    {
        $inherit = $inheritFrom ?? LicenseState::unlicensed();
        // Preserve a stored lifetime flag across a minimal refresh that carries
        // neither an expiry nor an explicit lifetime signal.
        $lifetime = $result->lifetime
            || ($inheritFrom !== null && $inherit->lifetime && !$result->lifetime && $result->expiresAt === null);
        $expiresAt = $lifetime ? null : ($result->expiresAt ?? $inherit->expiresAt);

        $this->repository->save(new LicenseState(
            key: $key,
            verifiedAt: $this->clock->now()->getTimestamp(),
            expiresAt: $expiresAt,
            domain: $domain,
            package: $result->package !== '' ? $result->package : $inherit->package,
            validationStatus: LicenseValidationStatus::Valid,
            issuedAt: $result->issuedAt ?? $inherit->issuedAt,
            features: $result->features !== [] ? $result->features : $inherit->features,
            freeAvailable: $result->freeAvailable ?: $inherit->freeAvailable,
            startsAt: $result->startsAt ?? $inherit->startsAt,
            lifetime: $lifetime,
            licenseVersion: $result->licenseVersion !== 0 ? $result->licenseVersion : $inherit->licenseVersion,
            signature: $result->signature !== '' ? $result->signature : $inherit->signature,
        ));
    }

    private function redactKey(string $message, string $key): string
    {
        return $key === '' ? $message : str_replace($key, '••••', $message);
    }
}
