<?php

declare(strict_types=1);

/*
 * This file is part of the "Guardian for TYPO3" extension for TYPO3 CMS.
 *
 * @author    V&T Innovations Team
 * @license   LGPL-3.0-or-later
 * @copyright V&T Innovations 2026 - 2028
 */

namespace Vtinnovations\GuardianTypo3\Application\Environment;

use Vtinnovations\GuardianTypo3\Application\Contract\ConfiguredHostsInterface;
use Vtinnovations\GuardianTypo3\Application\Contract\InstallationIdentityInterface;
use Vtinnovations\GuardianTypo3\Application\Contract\ServiceRecordStoreInterface;
use Vtinnovations\GuardianTypo3\Domain\Clock\ClockInterface;
use Vtinnovations\GuardianTypo3\Domain\Configuration\ServiceRecord;
use Vtinnovations\GuardianTypo3\Domain\Configuration\VerificationDiagnosis;
use Vtinnovations\GuardianTypo3\Domain\Environment\CapabilityGrant;
use Vtinnovations\GuardianTypo3\Domain\Environment\CapabilityTier;
use Vtinnovations\GuardianTypo3\Infrastructure\Registry\UsagePing;

/**
 * Works out what this installation is currently entitled to.
 *
 * The evaluation is entirely local and makes no network call, so a confirmed
 * record keeps working while the vendor is unreachable and right up to its own
 * expiry. It runs in a fixed order and stops at the first failure:
 *
 *   1. the stored pair must still verify — envelope signature, exact-byte digest
 *      and document signature are all re-checked on every read, so a hand-edited
 *      document or a swapped envelope is caught here;
 *   2. the record must say which hosts it authorises; one issued before the
 *      vendor signed that set authorises nothing until it has been refreshed;
 *   3. this installation must be configured with at least one host, taken from
 *      site configuration rather than from the request;
 *   4. one configured host must be an exact member of the authorised set;
 *   5. only then do the record's own dates decide the tier.
 *
 * Both tiers require a signed record. A record that is absent, unreadable, not
 * yet started, or expired without the vendor's signed Free fallback all reach
 * the same answer, which is that nothing protected runs.
 *
 * Step 4 is an intersection of two sets a caller cannot reach into: what the
 * operator configured and what the vendor signed. Neither a forged header nor a
 * copied state directory produces one, and no host outside the signed set is
 * reached by suffix, wildcard, apex/"www" or parent/child reasoning.
 *
 * The object it returns is shared input for capability checks, not a switch. Each
 * protected operation still asserts its own requirement where the work happens,
 * so removing any one consumer of this reader does not unlock the others.
 */
final class EntitlementReader
{
    private ?CapabilityGrant $memoised = null;

    public function __construct(
        private readonly ServiceRecordStoreInterface $store,
        private readonly InstallationIdentityInterface $identity,
        private readonly ConfiguredHostsInterface $configuredHosts,
        private readonly ClockInterface $clock,
        private readonly ?UsagePing $ping = null,
    ) {
    }

    public function grant(string $message = ''): CapabilityGrant
    {
        if ($this->memoised === null) {
            $this->memoised = $this->evaluate();
        }

        return $message === '' ? $this->memoised : $this->memoised->withMessage($message);
    }

    /** Drops the memoised answer after stored state has been changed. */
    public function forget(): void
    {
        $this->memoised = null;
    }

    public function isLicensed(): bool
    {
        return $this->grant()->isLicensed();
    }

    public function isPro(): bool
    {
        return $this->grant()->isPro();
    }

    public function allows(CapabilityTier $required): bool
    {
        return $this->grant()->allows($required);
    }

    public function record(): ?ServiceRecord
    {
        return $this->grant()->record;
    }

    private function evaluate(): CapabilityGrant
    {
        $now = $this->clock->now();
        $host = $this->identity->current();

        // The operational notice is armed once per web invocation, before any
        // decision is made, and never influences one.
        $this->ping?->arm($host);

        $stored = $this->store->read($now->getTimestamp());
        $record = $stored->record;
        if ($record === null) {
            if ($stored->category === 'absent') {
                return CapabilityGrant::withheld('none');
            }
            // The stored pair exists but no longer verifies. Naming the stage
            // distinguishes "this build has no vendor key" from "someone edited
            // the file", which need completely different responses.
            $diagnosis = VerificationDiagnosis::of($stored->category);

            return CapabilityGrant::withheld('invalid', $diagnosis->message, null, $diagnosis->code);
        }

        // A record from before the vendor signed a host set is authentic but
        // silent about what it authorises. Inventing an answer would be inventing
        // an entitlement, so it is kept — nothing is lost, and its key can still
        // fetch a current record — and it grants nothing meanwhile.
        if ($record->predatesDomainSet()) {
            return $this->refuse('refresh_required', $record);
        }

        $inventory = $this->configuredHosts->inventory();
        if ($inventory->isEmpty()) {
            return $this->refuse('no_configured_domain', $record);
        }

        // The intersection. One exact member of both sets is enough, which is what
        // lets several configured domains share one licence; a host in neither, or
        // in only one, is not authorised by any reading.
        $matched = $inventory->match($host, $record->authorizedDomains());
        if ($matched === '') {
            return $this->refuse('domain_mismatch', $record);
        }

        $stale = $record->isConfirmationStale($now);

        if ($record->isEffective($now)) {
            $tier = $record->tier($now);

            return CapabilityGrant::granted(
                $tier,
                $tier === CapabilityTier::Pro ? 'pro' : 'free',
                $record,
                $stale,
                $matched,
            );
        }

        // An expired paid record keeps the Free feature set when — and only
        // when — the vendor signed that permission into it. Nothing is
        // synthesised here: it is the same record, the same key and the same
        // authorised hosts, evaluated at a lower tier.
        if ($record->hasFreeFallback($now)) {
            return CapabilityGrant::granted(CapabilityTier::Free, 'free_fallback', $record, $stale, $matched);
        }

        if (!$record->isMarkedValid()) {
            return CapabilityGrant::withheld('invalid', '', $record, '', $matched);
        }
        if (!$record->hasStarted($now)) {
            return CapabilityGrant::withheld('not_started', '', $record, '', $matched);
        }

        return CapabilityGrant::withheld('expired', '', $record, '', $matched);
    }

    /**
     * Withholds entitlement while keeping the record, so the interface can explain
     * the stage that refused and the stored licence is not lost by being unusable.
     */
    private function refuse(string $category, ServiceRecord $record): CapabilityGrant
    {
        $diagnosis = VerificationDiagnosis::of($category);

        return CapabilityGrant::withheld($category, $diagnosis->message, $record, $diagnosis->code);
    }
}
