<?php

declare(strict_types=1);

/*
 * This file is part of the "Guardian for TYPO3" extension for TYPO3 CMS.
 *
 * @author    V&T Innovations Team
 * @license   LGPL-3.0-or-later
 * @copyright V&T Innovations 2026 - 2028
 */

namespace Vtinnovations\GuardianTypo3\Application\Configuration;

use Vtinnovations\GuardianTypo3\Application\Contract\ConfiguredHostsInterface;
use Vtinnovations\GuardianTypo3\Application\Contract\InstallationIdentityInterface;
use Vtinnovations\GuardianTypo3\Application\Contract\RecordExchangeInterface;
use Vtinnovations\GuardianTypo3\Application\Contract\ServiceRecordStoreInterface;
use Vtinnovations\GuardianTypo3\Application\Contract\SystemLoggerInterface;
use Vtinnovations\GuardianTypo3\Application\Environment\EntitlementReader;
use Vtinnovations\GuardianTypo3\Domain\Clock\ClockInterface;
use Vtinnovations\GuardianTypo3\Domain\Configuration\ProvisioningOutcome;
use Vtinnovations\GuardianTypo3\Domain\Configuration\ProvisioningStatus;
use Vtinnovations\GuardianTypo3\Domain\Configuration\ServiceRecord;
use Vtinnovations\GuardianTypo3\Domain\Configuration\VerificationDiagnosis;
use Vtinnovations\GuardianTypo3\Domain\Environment\CapabilityGrant;
use Vtinnovations\GuardianTypo3\Domain\Exception\GuardianException;

/**
 * The two administrator-initiated flows: activating a key for the first time and
 * refreshing the record already held.
 *
 * Both end in the same place — a complete, signed package is stored, or nothing
 * changes. The rules that make that safe are:
 *
 *   - a package is only stored after it has verified end to end and is bound to
 *     exactly this installation's host;
 *   - a transport failure, a timeout, a malformed answer or a failed check never
 *     removes a working record, so nobody loses their entitlement because the
 *     vendor had an outage;
 *   - a refresh cannot move the record backwards to an older version;
 *   - an explicit, correlated refusal of the key that is currently stored does
 *     withdraw it, because that is how a revoked key stops working. A refusal of
 *     some *other* key — a typo in a replacement, say — leaves the working record
 *     untouched.
 *
 * Only coarse metadata is recorded: which flow ran, how it ended, and the
 * resulting version. The key, the packet, the payload, the digest and the
 * signatures never reach a log.
 */
final class ActivationService
{
    private const LOG_ORIGIN = 'entitlement';

    public function __construct(
        private readonly ServiceRecordStoreInterface $store,
        private readonly RecordExchangeInterface $exchange,
        private readonly InstallationIdentityInterface $identity,
        private readonly ConfiguredHostsInterface $configuredHosts,
        private readonly EntitlementReader $reader,
        private readonly ClockInterface $clock,
        private readonly SystemLoggerInterface $logger,
    ) {
    }

    /**
     * The host an exchange is carried out for.
     *
     * It is chosen from site configuration, not from the URL the administrator
     * happens to be using: the name being served is used when it is one of the
     * configured ones, and the installation's primary configured name otherwise.
     * That is what makes a backend reached under its own hostname verify the site
     * it belongs to rather than itself, and it is the same choice on every node.
     *
     * An empty string means the installation names no host at all, which is a
     * refusal rather than a licence-free pass.
     */
    private function exchangeHost(): string
    {
        return $this->configuredHosts->inventory()->select($this->identity->current());
    }

    /**
     * Activates a key an administrator entered. An empty key means "use the one
     * already stored", which is what the Update Licence button does — the key
     * itself never travels to the browser and back.
     */
    public function activate(string $key): CapabilityGrant
    {
        $now = $this->clock->now()->getTimestamp();
        $stored = $this->store->read($now);

        // Activation is an administrator action taken in the backend, so a live
        // request must have established where we are before anything is claimed.
        if ($this->identity->current() === '' || !$this->identity->isLive()) {
            return $this->diagnose('host_unresolved');
        }
        $host = $this->exchangeHost();
        if ($host === '') {
            return $this->diagnose('no_configured_domain');
        }

        $key = trim($key);
        $reusedStoredKey = false;
        if ($stored->record !== null && ($key === '' || hash_equals($stored->record->key, $key))) {
            // Either the field was left blank or the administrator re-entered the
            // key already held; both mean "re-confirm what I have".
            $key = $stored->record->key;
            $reusedStoredKey = true;
        }
        if ($key === '' || strlen($key) > ServiceRecord::MAX_KEY_LENGTH) {
            return $this->reader->grant('Please enter a valid licence key.');
        }

        // An administrator pressing Update Licence for the key already held is a
        // refresh in the protocol's sense, and must announce the version it has.
        if ($reusedStoredKey && $stored->record !== null) {
            return $this->applyRefresh($stored->record, $key, $host, $now);
        }

        $outcome = $this->exchange->activate($key, $host, $now);

        return $this->settle($outcome, 'activate', $key, $now);
    }

    /** Re-confirms the stored record without an administrator retyping the key. */
    public function refresh(): CapabilityGrant
    {
        $now = $this->clock->now()->getTimestamp();
        $stored = $this->store->read($now);

        if ($stored->record === null) {
            return $this->reader->grant();
        }
        // The host set the vendor previously signed is deliberately not consulted
        // here: a refresh is how a licence that has just gained a domain arrives,
        // so asking about a host the stored record does not yet cover is the
        // normal case. The vendor decides; this side only says where it is.
        $host = $this->exchangeHost();
        if ($host === '') {
            return $this->diagnose('no_configured_domain');
        }

        return $this->applyRefresh($stored->record, $stored->record->key, $host, $now);
    }

    /** Removes the stored record at the administrator's request. */
    public function withdraw(): CapabilityGrant
    {
        $this->store->discard();
        $this->reader->forget();
        $this->note('withdraw', 'removed', null);

        return $this->reader->grant();
    }

    private function applyRefresh(ServiceRecord $current, string $key, string $host, int $now): CapabilityGrant
    {
        $outcome = $this->exchange->refresh($key, $host, $current->version, $now);

        // A refresh may confirm the version already held (nothing changed) but
        // must never hand back an older one.
        if ($outcome->isConfirmed() && $outcome->record !== null && $outcome->record->version < $current->version) {
            $this->note('refresh', 'rejected_version', null);

            return $this->diagnose('rejected_version');
        }

        return $this->settle($outcome, 'refresh', $key, $now);
    }

    private function settle(ProvisioningOutcome $outcome, string $operation, string $attemptedKey, int $now): CapabilityGrant
    {
        if ($outcome->isConfirmed() && $outcome->record !== null && $outcome->documentBytes !== null) {
            try {
                $this->store->replace($outcome->documentBytes, $outcome->envelope, $now);
            } catch (GuardianException) {
                $this->note($operation, 'storage_failed', null);

                return $this->diagnose('storage_failed');
            }
            $this->reader->forget();
            $this->note($operation, 'confirmed', $outcome->record->version);

            return $this->reader->grant();
        }

        if ($outcome->status === ProvisioningStatus::Denied) {
            return $this->handleDenial($operation, $attemptedKey, $now);
        }

        $this->note($operation, $outcome->category, null);
        $this->reader->forget();

        // The stage that refused is reported either way; when a working record
        // survives, the wording says so rather than implying it was lost.
        $diagnosis = VerificationDiagnosis::of($outcome->category);
        if ($this->reader->grant()->record !== null) {
            $diagnosis = $diagnosis->withRetainedLicence();
        }

        return $this->reader->grant()->withDiagnosis($diagnosis);
    }

    /**
     * A refusal only withdraws the record when it refers to the key that record
     * holds. Anything else leaves the working entitlement alone.
     */
    private function handleDenial(string $operation, string $attemptedKey, int $now): CapabilityGrant
    {
        $stored = $this->store->read($now);
        if ($stored->record !== null && hash_equals($stored->record->key, $attemptedKey)) {
            $this->store->discard();
            $this->reader->forget();
            $this->note($operation, 'withdrawn', null);

            return $this->diagnose('withdrawn');
        }

        $this->note($operation, 'denied', null);
        $this->reader->forget();

        return $this->diagnose($stored->record !== null ? 'denied_replacement' : 'denied');
    }

    /**
     * Returns the current entitlement carrying the code and sentence for the
     * stage that just refused, so the interface can explain the outcome instead
     * of restating what happens to be stored.
     */
    private function diagnose(string $category): CapabilityGrant
    {
        return $this->reader->grant()->withDiagnosis(VerificationDiagnosis::of($category));
    }

    /**
     * Records the flow, its coarse result and the version now in effect. Nothing
     * else — no key, no packet, no digest, no signature — is ever written here.
     */
    private function note(string $operation, string $result, ?int $version): void
    {
        $message = sprintf(
            'Entitlement %s finished with result "%s"%s.',
            $operation,
            $result,
            $version !== null ? sprintf(' (record version %d)', $version) : ''
        );
        if ($result === 'confirmed' || $result === 'removed') {
            $this->logger->info($message, self::LOG_ORIGIN);

            return;
        }
        $this->logger->warning($message, self::LOG_ORIGIN);
    }
}
