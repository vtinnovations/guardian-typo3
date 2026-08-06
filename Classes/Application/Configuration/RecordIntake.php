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
use Vtinnovations\GuardianTypo3\Application\Contract\ServiceRecordStoreInterface;
use Vtinnovations\GuardianTypo3\Application\Contract\SystemLoggerInterface;
use Vtinnovations\GuardianTypo3\Application\Environment\EntitlementReader;
use Vtinnovations\GuardianTypo3\Domain\Configuration\RecordIntakeOutcome;
use Vtinnovations\GuardianTypo3\Domain\Configuration\ServiceRecord;
use Vtinnovations\GuardianTypo3\Domain\Environment\HostIdentity;
use Vtinnovations\GuardianTypo3\Domain\Exception\GuardianException;
use Vtinnovations\GuardianTypo3\Infrastructure\Exchange\RequestJournal;
use Vtinnovations\GuardianTypo3\Infrastructure\Manifest\SealedPackage;
use Vtinnovations\GuardianTypo3\Typo3\Authorization\SignedRequestIdentity;

/**
 * Applies a record the vendor pushed to this installation.
 *
 * By the time this runs the request has already been proven to come from the
 * vendor; what is left is deciding whether it should be acted on, and doing so
 * exactly once:
 *
 *   - the push must name this product and a host this installation is actually
 *     configured to serve — read from site configuration, so the name cannot be
 *     supplied by whoever is pushing;
 *   - the identifier is claimed before any work happens, so the same request
 *     arriving twice is answered from the journal rather than applied twice, and
 *     the same identifier carrying *different* content is refused outright;
 *   - the package must verify end to end and be bound to this exact host;
 *   - it must be newer than what is stored, so a correctly signed but older
 *     record cannot be used to roll the installation back;
 *   - the swap is atomic, and a swap that does not verify afterwards is undone.
 *
 * Nothing here writes source code, chooses a path or takes a file name from the
 * request. The only thing that changes is the record pair in Guardian's private
 * working directory.
 */
final class RecordIntake
{
    private const LOG_ORIGIN = 'entitlement';

    public function __construct(
        private readonly ServiceRecordStoreInterface $store,
        private readonly SealedPackage $package,
        private readonly RequestJournal $journal,
        private readonly ConfiguredHostsInterface $configuredHosts,
        private readonly EntitlementReader $reader,
        private readonly SystemLoggerInterface $logger,
    ) {
    }

    /**
     * @param array<string, mixed> $body decoded, already-authenticated request body
     */
    public function accept(SignedRequestIdentity $identity, array $body, string $rawBody, int $now): RecordIntakeOutcome
    {
        if (!$identity->authenticated) {
            return $this->refuse(RecordIntakeOutcome::unauthenticated($identity->category));
        }

        // The push must be about this product and this installation.
        if (($body['action'] ?? null) !== 'license_update'
            || ($body['project'] ?? null) !== ServiceRecord::PROJECT
            || ($body['project_slug'] ?? null) !== ServiceRecord::PROJECT_SLUG
            || ($body['product_id'] ?? null) !== ServiceRecord::PRODUCT_ID
        ) {
            return $this->refuse(RecordIntakeOutcome::refused('product_mismatch'));
        }

        // The host the push is about must be one this installation is configured
        // to serve. It is compared against site configuration rather than against
        // the name this particular request happened to arrive under, so a push
        // that reaches a second configured domain of the same installation is
        // still recognised — while a push naming a host that was never configured
        // here is refused whatever it arrived as.
        $host = HostIdentity::normalize(\is_string($body['domain'] ?? null) ? $body['domain'] : '');
        if ($host === '' || !$this->configuredHosts->inventory()->contains($host)) {
            return $this->refuse(RecordIntakeOutcome::refused('host_mismatch'));
        }

        // Claim the identifier before doing anything, so concurrent copies of the
        // same push cannot both decide they are the first.
        $fingerprint = hash('sha256', $rawBody);
        $claim = $this->journal->claim($identity->requestId, $fingerprint, $identity->nonceDigest(), $now);

        if ($claim->known) {
            if (!$claim->matches($fingerprint)) {
                // Same identifier, different content: this is not a retry.
                return $this->refuse(RecordIntakeOutcome::conflict('request_id_reused'));
            }
            $this->note('already_processed', $claim->version);

            return RecordIntakeOutcome::alreadyProcessed($identity->requestId, $claim->version);
        }
        if (!$claim->granted) {
            return $this->refuse(RecordIntakeOutcome::unauthenticated('replayed_request'));
        }

        $outcome = $this->apply($identity, $body, $host, $now);

        if ($outcome->isSuccess()) {
            $this->journal->settle($identity->requestId, $outcome->status, $outcome->version, $now);
        } else {
            // A claim that produced no change is given back, so a corrected push
            // is not blocked by a reservation that did nothing. The one-use value
            // stays consumed.
            $this->journal->release($identity->requestId, $now);
        }

        return $outcome;
    }

    /**
     * @param array<string, mixed> $body
     */
    private function apply(SignedRequestIdentity $identity, array $body, string $host, int $now): RecordIntakeOutcome
    {
        $payload = $body['license_payload_b64'] ?? null;
        $envelope = $body['integrity'] ?? null;
        if (!\is_string($payload) || !\is_array($envelope) || array_is_list($envelope)) {
            return $this->refuse(RecordIntakeOutcome::malformed('package_incomplete'));
        }

        $opened = $this->package->open($payload, $envelope, $now);
        if (!$opened->trusted || $opened->record === null) {
            return $this->refuse(RecordIntakeOutcome::refused($opened->category));
        }

        // The two authenticated statements must agree: the host named in the push
        // body and the host the signed record was issued for.
        if (!HostIdentity::equals($opened->record->host, $host)) {
            return $this->refuse(RecordIntakeOutcome::refused('host_binding_mismatch'));
        }
        // A pushed record that does not list the domains it covers would replace a
        // working licence with one that grants nothing.
        if ($opened->record->predatesDomainSet()) {
            return $this->refuse(RecordIntakeOutcome::refused('record_domains_missing'));
        }

        $current = $this->store->read($now)->record;
        if ($current !== null) {
            if ($opened->record->version <= $current->version) {
                return $this->refuse(RecordIntakeOutcome::conflict('not_newer'));
            }
            // A push may renew or re-tier a record, but it may not silently
            // replace the key this installation activated.
            if (!hash_equals($current->key, $opened->record->key)) {
                return $this->refuse(RecordIntakeOutcome::refused('key_mismatch'));
            }
        }

        try {
            $this->store->replace($opened->documentBytes, $opened->envelope, $now);
        } catch (GuardianException) {
            return $this->refuse(RecordIntakeOutcome::rolledBack());
        }

        // Confirm what is actually live now; the store rolls back on its own if
        // this fails, and the refusal is reported either way.
        $this->reader->forget();
        $reloaded = $this->store->read($now)->record;
        if ($reloaded === null || $reloaded->version !== $opened->record->version) {
            return $this->refuse(RecordIntakeOutcome::rolledBack());
        }

        $this->note('updated', $reloaded->version);

        return RecordIntakeOutcome::updated($identity->requestId, $reloaded->version);
    }

    private function refuse(RecordIntakeOutcome $outcome): RecordIntakeOutcome
    {
        $this->logger->warning(
            sprintf('Inbound entitlement push refused (%s, HTTP %d).', $outcome->category, $outcome->httpStatus),
            self::LOG_ORIGIN
        );

        return $outcome;
    }

    private function note(string $result, ?int $version): void
    {
        $this->logger->info(
            sprintf(
                'Inbound entitlement push result "%s"%s.',
                $result,
                $version !== null ? sprintf(' (record version %d)', $version) : ''
            ),
            self::LOG_ORIGIN
        );
    }
}
