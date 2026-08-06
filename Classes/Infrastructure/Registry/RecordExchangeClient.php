<?php

declare(strict_types=1);

/*
 * This file is part of the "Guardian for TYPO3" extension for TYPO3 CMS.
 *
 * @author    V&T Innovations Team
 * @license   LGPL-3.0-or-later
 * @copyright V&T Innovations 2026 - 2028
 */

namespace Vtinnovations\GuardianTypo3\Infrastructure\Registry;

use Psr\Http\Message\ResponseInterface;
use Vtinnovations\GuardianTypo3\Application\Contract\RecordExchangeInterface;
use Vtinnovations\GuardianTypo3\Domain\Configuration\ProvisioningOutcome;
use Vtinnovations\GuardianTypo3\Domain\Configuration\ServiceRecord;
use Vtinnovations\GuardianTypo3\Domain\Configuration\VerificationDiagnosis;
use Vtinnovations\GuardianTypo3\Domain\Environment\HostIdentity;
use Vtinnovations\GuardianTypo3\Infrastructure\Manifest\SealedPackage;
use Vtinnovations\GuardianTypo3\Infrastructure\Registry\Transport\ExchangeTransportInterface;

/**
 * Talks to the vendor's exchange endpoint for a first activation and for an
 * administrator refresh.
 *
 * The request carries only what the protocol defines: the action, the product
 * identity, the key, this installation's host, a fresh identifier, the current
 * time and a one-use value. Nothing about the site, its users, its content or its
 * environment is sent.
 *
 * The answer is treated as hostile until it has earned otherwise. The status
 * code, media type and size are checked before the body is parsed; the reply must
 * quote back the identifier that was sent; the clock difference must be
 * plausible; and only then is the package opened and required to be bound to
 * exactly the host that asked. A failure at any of these points returns an
 * outcome that leaves stored state alone — a timeout or a server error can never
 * be the reason an installation loses its entitlement.
 */
final class RecordExchangeClient implements RecordExchangeInterface
{
    /** How far the vendor's clock may differ from ours before we distrust it. */
    private const MAX_CLOCK_SKEW_SECONDS = 900;

    public function __construct(
        private readonly ExchangeTransportInterface $transport,
        private readonly ServiceEndpoint $endpoint,
        private readonly SealedPackage $package,
    ) {
    }

    public function activate(string $key, string $host, int $now): ProvisioningOutcome
    {
        return $this->exchange($this->packet('activate', $key, $host, $now), $host, $now);
    }

    public function refresh(string $key, string $host, int $currentVersion, int $now): ProvisioningOutcome
    {
        $packet = $this->packet('refresh', $key, $host, $now);
        $packet['current_license_version'] = $currentVersion;

        return $this->exchange($packet, $host, $now);
    }

    /**
     * @return array<string, mixed>
     */
    private function packet(string $action, string $key, string $host, int $now): array
    {
        return [
            'action' => $action,
            'project' => ServiceRecord::PROJECT,
            'project_slug' => ServiceRecord::PROJECT_SLUG,
            'product_id' => ServiceRecord::PRODUCT_ID,
            'license_key' => $key,
            'domain' => $host,
            'request_id' => bin2hex(random_bytes(16)),
            'timestamp' => $now,
            'nonce' => bin2hex(random_bytes(32)),
        ];
    }

    /**
     * @param array<string, mixed> $packet
     */
    private function exchange(array $packet, string $host, int $now): ProvisioningOutcome
    {
        $host = HostIdentity::normalize($host);
        if ($host === '' || $host !== $packet['domain']) {
            return $this->refuse('host_unresolved');
        }

        try {
            $response = $this->transport->post(
                $this->endpoint->exchange(),
                $packet,
                ServiceEndpoint::CONNECT_TIMEOUT_SECONDS,
                ServiceEndpoint::TOTAL_TIMEOUT_SECONDS,
            );
        } catch (\Throwable) {
            return ProvisioningOutcome::unreachable(
                'transport_failed',
                VerificationDiagnosis::of('transport_failed')->message,
            );
        }

        return $this->interpret($response, $packet, $host, $now);
    }

    /**
     * Refuses the exchange, carrying the precise stage that failed. The public
     * sentence comes from the shared diagnosis so the wording stays consistent
     * wherever it surfaces — and so no packet material is ever placed in it.
     */
    private function refuse(string $category): ProvisioningOutcome
    {
        return ProvisioningOutcome::rejected($category, VerificationDiagnosis::of($category)->message);
    }

    /**
     * @param array<string, mixed> $packet
     */
    private function interpret(ResponseInterface $response, array $packet, string $host, int $now): ProvisioningOutcome
    {
        $status = $response->getStatusCode();
        // A redirect was refused rather than followed, so treat it as an outage
        // instead of chasing it.
        if ($status >= 500 || $status === 0 || ($status >= 300 && $status < 400)) {
            return ProvisioningOutcome::unreachable(
                'service_unavailable',
                VerificationDiagnosis::of('service_unavailable')->message,
            );
        }
        if ($status !== 200) {
            return $this->refuse('unexpected_status');
        }

        $mediaType = strtolower(trim(explode(';', $response->getHeaderLine('Content-Type'), 2)[0]));
        if ($mediaType !== ServiceEndpoint::MEDIA_TYPE) {
            return $this->refuse('unexpected_media_type');
        }

        $body = $response->getBody()->read(ServiceEndpoint::MAX_RESPONSE_BYTES + 1);
        if (strlen($body) > ServiceEndpoint::MAX_RESPONSE_BYTES) {
            return $this->refuse('response_too_large');
        }

        $decoded = json_decode($body, true);
        if (!\is_array($decoded) || array_is_list($decoded)) {
            return $this->refuse('response_malformed');
        }

        // The reply must be about the request we actually made.
        $correlation = $decoded['request_id'] ?? null;
        if (!\is_string($correlation) || !hash_equals((string) $packet['request_id'], $correlation)) {
            return $this->refuse('response_uncorrelated');
        }

        $serverTime = $decoded['server_time'] ?? null;
        if (!\is_int($serverTime) || abs($serverTime - $now) > self::MAX_CLOCK_SKEW_SECONDS) {
            return $this->refuse('response_clock_skew');
        }

        $reported = \is_string($decoded['status'] ?? null) ? $decoded['status'] : '';
        if ($reported !== 'valid') {
            // An authenticated refusal is the vendor's decision, not a fault.
            return ProvisioningOutcome::denied(VerificationDiagnosis::of('denied')->message);
        }

        $envelope = $decoded['integrity'] ?? null;
        $payload = $decoded['license_payload_b64'] ?? null;
        if (!\is_array($envelope) || array_is_list($envelope) || !\is_string($payload)) {
            return $this->refuse('response_malformed');
        }

        $opened = $this->package->open($payload, $envelope, $now);
        if (!$opened->trusted || $opened->record === null) {
            // The precise stage that refused is carried through rather than
            // flattened, so the administrator learns whether this build is
            // missing the vendor key, the signature was wrong, or the document
            // itself was unusable.
            return $this->refuse($opened->category);
        }

        // The signed binding must name exactly the host that asked.
        if (!HostIdentity::equals($opened->record->host, $host)) {
            return $this->refuse('host_binding_mismatch');
        }
        // A freshly issued record must say which hosts it authorises. Storing one
        // that does not would replace a working licence with a record that can
        // grant nothing, so the previous state is kept and the gap is reported.
        if ($opened->record->predatesDomainSet()) {
            return $this->refuse('record_domains_missing');
        }
        if (!hash_equals($opened->record->key, (string) $packet['license_key'])) {
            return $this->refuse('key_binding_mismatch');
        }

        return ProvisioningOutcome::confirmed($opened->record, $opened->documentBytes, $opened->envelope);
    }
}
