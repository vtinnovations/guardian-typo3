<?php

declare(strict_types=1);

/*
 * This file is part of the "Guardian for TYPO3" extension for TYPO3 CMS.
 *
 * @author    V&T Innovations Team
 * @license   LGPL-3.0-or-later
 * @copyright V&T Innovations 2026 - 2028
 */

namespace Vtinnovations\GuardianTypo3\Tests\Unit\Integration;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Vtinnovations\GuardianTypo3\Application\Configuration\ActivationService;
use Vtinnovations\GuardianTypo3\Application\Environment\EntitlementReader;
use Vtinnovations\GuardianTypo3\Infrastructure\Configuration\SealedRecordStore;
use Vtinnovations\GuardianTypo3\Infrastructure\Manifest\CanonicalForm;
use Vtinnovations\GuardianTypo3\Infrastructure\Manifest\DetachedSignature;
use Vtinnovations\GuardianTypo3\Infrastructure\Manifest\SealedPackage;
use Vtinnovations\GuardianTypo3\Infrastructure\Registry\RecordExchangeClient;
use Vtinnovations\GuardianTypo3\Infrastructure\Registry\ServiceEndpoint;
use Vtinnovations\GuardianTypo3\Infrastructure\Registry\Transport\ExchangeTransportInterface;
use Vtinnovations\GuardianTypo3\Infrastructure\Version\ReleaseKeyring;
use Vtinnovations\GuardianTypo3\Tests\Unit\Support\FixedClock;
use Vtinnovations\GuardianTypo3\Tests\Unit\Support\FixedHosts;
use Vtinnovations\GuardianTypo3\Tests\Unit\Support\FixedIdentity;
use Vtinnovations\GuardianTypo3\Tests\Unit\Support\InMemoryLockFactory;
use Vtinnovations\GuardianTypo3\Tests\Unit\Support\RecordPackageFactory;
use Vtinnovations\GuardianTypo3\Tests\Unit\Support\RecordingLogger;
use Vtinnovations\GuardianTypo3\Tests\Unit\Support\TempWorkingDirectory;
use Psr\Http\Message\ResponseInterface;
use TYPO3\CMS\Core\Http\Response;
use TYPO3\CMS\Core\Http\Stream;

/**
 * The reported symptom, reproduced end to end and then pinned down.
 *
 * A genuinely valid licence used to produce "The license could not be verified."
 * — a sentence that fits a missing verification key, an expired licence, a wrong
 * domain and a vendor outage equally well, and therefore told the administrator
 * nothing. These tests assert that the stage which actually refused now reaches
 * the interface, and that the licence itself is exonerated: the very same packet
 * verifies as soon as a key is pinned.
 */
final class EmptyKeyRingReportingTest extends TestCase
{
    private const NOW = 1784880600;
    private const HOST = 'example.com';

    private string $base;
    private RecordPackageFactory $vendor;
    private FixedClock $clock;
    private RecordingLogger $logger;

    protected function setUp(): void
    {
        $this->base = sys_get_temp_dir() . '/guardian-emptyring-' . bin2hex(random_bytes(6));
        $this->vendor = new RecordPackageFactory('vtone-2026a');
        $this->clock = new FixedClock(self::NOW);
        $this->logger = new RecordingLogger();
    }

    protected function tearDown(): void
    {
        if (is_dir($this->base)) {
            exec('rm -rf ' . escapeshellarg($this->base));
        }
    }

    /**
     * A transport that returns a correctly signed, correlated activation
     * response — so nothing about the packet is at fault.
     */
    private function transport(): ExchangeTransportInterface
    {
        $package = $this->vendor->package(['license_domain' => self::HOST]);

        return new class ($package) implements ExchangeTransportInterface {
            /** @param array{payload: string, envelope: array<string, mixed>} $package */
            public function __construct(private readonly array $package)
            {
            }

            public function post(string $url, array $packet, float $connectTimeout, float $totalTimeout): ResponseInterface
            {
                $body = [
                    'status' => 'valid',
                    'request_id' => $packet['request_id'],
                    'server_time' => $packet['timestamp'],
                    'license_payload_b64' => $this->package['payload'],
                    'integrity' => $this->package['envelope'],
                ];
                $stream = new Stream('php://temp', 'rw');
                $stream->write((string) json_encode($body));
                $stream->rewind();

                return (new Response($stream, 200))->withHeader('Content-Type', 'application/json');
            }
        };
    }

    private function activationService(ReleaseKeyring $ring): ActivationService
    {
        $sealed = new SealedPackage(new CanonicalForm(), new DetachedSignature($ring));
        $store = new SealedRecordStore(
            new TempWorkingDirectory($this->base),
            $sealed,
            new InMemoryLockFactory(),
        );
        $identity = new FixedIdentity(self::HOST);
        $hosts = new FixedHosts(self::HOST);
        $reader = new EntitlementReader($store, $identity, $hosts, $this->clock, null);
        $client = new RecordExchangeClient($this->transport(), new ServiceEndpoint(), $sealed);

        return new ActivationService($store, $client, $identity, $hosts, $reader, $this->clock, $this->logger);
    }

    #[Test]
    public function aValidLicenceIsRefusedByABuildWithNoVerificationKeyPinned(): void
    {
        // A build that carries no key: every signed exchange fails closed, and the
        // reason names the build rather than blaming the licence.
        $grant = $this->activationService(new ReleaseKeyring([]))->activate('GRD-TEST-0001-0002-0003');

        self::assertFalse($grant->isLicensed());
        self::assertSame('signing_key_store_empty', $grant->code);
        self::assertStringContainsString('approved V-T.ONE verification key', $grant->message);
    }

    #[Test]
    public function theShippedBuildCarriesAKeyRatherThanFailingThisWay(): void
    {
        // The refusal above is a build defect, and this asserts the shipped build
        // does not have it — the two together are what make the diagnosis useful.
        self::assertFalse((new ReleaseKeyring())->isEmpty());
        self::assertSame([], (new ReleaseKeyring())->productionReadiness());
    }

    #[Test]
    public function theSameLicenceIsAcceptedOnceTheKeyIsPinned(): void
    {
        // Identical packet, identical flow — only the pinned key differs. This is
        // what proves the licence key and the packet were never the problem.
        $grant = $this->activationService($this->vendor->keyring())->activate('GRD-TEST-0001-0002-0003');

        self::assertTrue($grant->isLicensed());
        self::assertTrue($grant->isPro());
        self::assertSame('', $grant->code);
    }

    #[Test]
    public function theAdministratorPayloadCarriesTheCodeAndNoPacketMaterial(): void
    {
        $grant = $this->activationService(new ReleaseKeyring([]))->activate('GRD-TEST-0001-0002-0003');
        $payload = $grant->toPublicArray();

        // The code is exposed under its own key, so the controller's transport
        // level "code" cannot silently displace it when the arrays are merged.
        self::assertSame('signing_key_store_empty', $payload['verification_code']);
        self::assertNotSame('', $payload['message']);

        $encoded = (string) json_encode($payload);
        foreach ([
            'GRD-TEST-0001-0002-0003',
            'license_payload_b64',
            'license_md5',
            'nonce',
        ] as $forbidden) {
            self::assertStringNotContainsString($forbidden, $encoded, $forbidden);
        }
    }

    #[Test]
    public function theMergeInTheControllerKeepsBothCodes(): void
    {
        // Reproduces exactly what GuardianAjaxController does. PHP's array union
        // favours the left operand, so a collision would have silently dropped
        // the verification code.
        $grant = $this->activationService(new ReleaseKeyring([]))->activate('GRD-TEST-0001-0002-0003');
        $response = ['success' => true, 'code' => 'ok', 'valid' => $grant->isLicensed()] + $grant->toPublicArray();

        self::assertSame('ok', $response['code'], 'the transport-level result');
        self::assertSame('signing_key_store_empty', $response['verification_code'], 'the verification result');
        self::assertFalse($response['valid']);
    }

    #[Test]
    public function theAuditTrailRecordsTheStageWithoutAnyPacketMaterial(): void
    {
        $this->activationService(new ReleaseKeyring([]))->activate('GRD-TEST-0001-0002-0003');
        $transcript = $this->logger->transcript();

        self::assertStringContainsString('signing_key_store_empty', $transcript);
        foreach ([
            'GRD-TEST-0001-0002-0003',
            'license_payload_b64',
            'license_md5',
            'nonce',
            'signature',
        ] as $forbidden) {
            self::assertStringNotContainsString($forbidden, $transcript, $forbidden);
        }
    }

    #[Test]
    public function anUnknownKeyIsReportedDifferentlyFromAMissingOne(): void
    {
        // A populated ring that does not carry the advertised id must not be
        // reported as "this build has no key": the remedies differ.
        $otherKey = (new RecordPackageFactory('vtone-2027b'))->keyring();
        $grant = $this->activationService($otherKey)->activate('GRD-TEST-0001-0002-0003');

        self::assertFalse($grant->isLicensed());
        self::assertSame('unknown_signing_key', $grant->code);
    }

    #[Test]
    public function aWrongSignatureIsReportedAsASignatureFailure(): void
    {
        // Right key id, wrong key material: the signature check itself fails.
        $grant = $this->activationService($this->vendor->foreignKeyring())->activate('GRD-TEST-0001-0002-0003');

        self::assertFalse($grant->isLicensed());
        self::assertSame('integrity_signature_invalid', $grant->code);
    }
}
