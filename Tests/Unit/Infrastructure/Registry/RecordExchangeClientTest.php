<?php

declare(strict_types=1);

/*
 * This file is part of the "Guardian for TYPO3" extension for TYPO3 CMS.
 *
 * @author    V&T Innovations Team
 * @license   LGPL-3.0-or-later
 * @copyright V&T Innovations 2026 - 2028
 */

namespace Vtinnovations\GuardianTypo3\Tests\Unit\Infrastructure\Registry;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Psr\Http\Message\ResponseInterface;
use TYPO3\CMS\Core\Http\Response;
use TYPO3\CMS\Core\Http\Stream;
use Vtinnovations\GuardianTypo3\Domain\Configuration\ProvisioningOutcome;
use Vtinnovations\GuardianTypo3\Domain\Configuration\ProvisioningStatus;
use Vtinnovations\GuardianTypo3\Infrastructure\Registry\RecordExchangeClient;
use Vtinnovations\GuardianTypo3\Infrastructure\Registry\ServiceEndpoint;
use Vtinnovations\GuardianTypo3\Infrastructure\Registry\Transport\ExchangeTransportInterface;
use Vtinnovations\GuardianTypo3\Tests\Unit\Support\RecordPackageFactory;

/**
 * The outbound half of the protocol: what leaves the installation, and how
 * sceptically the answer is treated.
 *
 * No live endpoint is contacted; the HTTP client is replaced with a double that
 * records the call and returns a scripted answer.
 */
final class RecordExchangeClientTest extends TestCase
{
    private const NOW = 1784880600;
    private const HOST = 'example.com';

    private RecordPackageFactory $vendor;

    protected function setUp(): void
    {
        $this->vendor = new RecordPackageFactory();
    }

    private function factory(ResponseInterface|\Throwable $answer): ExchangeTransportInterface
    {
        return new class ($answer) implements ExchangeTransportInterface {
            /** @var list<array{url: string, packet: array<string, mixed>, connect: float, total: float}> */
            public array $calls = [];

            public function __construct(private readonly ResponseInterface|\Throwable $answer)
            {
            }

            public function post(string $url, array $packet, float $connectTimeout, float $totalTimeout): ResponseInterface
            {
                $this->calls[] = ['url' => $url, 'packet' => $packet, 'connect' => $connectTimeout, 'total' => $totalTimeout];
                if ($this->answer instanceof \Throwable) {
                    throw $this->answer;
                }

                return $this->answer;
            }
        };
    }

    /**
     * @param array<string, mixed> $body
     */
    private function jsonResponse(array $body, int $status = 200, string $contentType = 'application/json'): ResponseInterface
    {
        $stream = new Stream('php://temp', 'rw');
        $stream->write((string) json_encode($body));
        $stream->rewind();

        return (new Response($stream, $status))->withHeader('Content-Type', $contentType);
    }

    private function client(ExchangeTransportInterface $transport): RecordExchangeClient
    {
        return new RecordExchangeClient($transport, new ServiceEndpoint(), $this->vendor->sealedPackage());
    }

    #[Test]
    public function anActivationSendsExactlyTheDocumentedFields(): void
    {
        $package = $this->vendor->package();
        $factory = $this->factory($this->jsonResponse([
            'status' => 'valid',
            'request_id' => 'placeholder',
            'server_time' => self::NOW,
            'license_payload_b64' => $package['payload'],
            'integrity' => $package['envelope'],
        ]));

        $this->client($factory)->activate('GRD-TEST-0001-0002-0003', self::HOST, self::NOW);

        $packet = $factory->calls[0]['packet'];
        self::assertSame([
            'action',
            'project',
            'project_slug',
            'product_id',
            'license_key',
            'domain',
            'request_id',
            'timestamp',
            'nonce',
        ], array_keys($packet));
        self::assertSame('activate', $packet['action']);
        self::assertSame('Guardian', $packet['project']);
        self::assertSame('guardian', $packet['project_slug']);
        self::assertSame('vt-guardian', $packet['product_id']);
        self::assertSame('GRD-TEST-0001-0002-0003', $packet['license_key']);
        self::assertSame(self::HOST, $packet['domain']);
        self::assertSame(self::NOW, $packet['timestamp']);
        self::assertNotSame('', $packet['request_id']);
        self::assertNotSame('', $packet['nonce']);
    }

    #[Test]
    public function aRefreshAdditionallyAnnouncesTheVersionAlreadyHeld(): void
    {
        $factory = $this->factory($this->jsonResponse(['status' => 'invalid', 'request_id' => 'x', 'server_time' => self::NOW]));

        $this->client($factory)->refresh('GRD-KEY', self::HOST, 7, self::NOW);

        $packet = $factory->calls[0]['packet'];
        self::assertSame('refresh', $packet['action']);
        self::assertSame(7, $packet['current_license_version']);
    }

    #[Test]
    public function everyRequestGetsAFreshIdentifierAndOneTimeValue(): void
    {
        $factory = $this->factory($this->jsonResponse(['status' => 'invalid', 'request_id' => 'x', 'server_time' => self::NOW]));
        $client = $this->client($factory);

        $client->activate('GRD-KEY', self::HOST, self::NOW);
        $client->activate('GRD-KEY', self::HOST, self::NOW);

        $first = $factory->calls[0]['packet'];
        $second = $factory->calls[1]['packet'];
        self::assertNotSame($first['request_id'], $second['request_id']);
        self::assertNotSame($first['nonce'], $second['nonce']);
    }

    #[Test]
    public function theDestinationIsFixedAndTheTransportIsLockedDown(): void
    {
        $factory = $this->factory($this->jsonResponse(['status' => 'invalid', 'request_id' => 'x', 'server_time' => self::NOW]));

        $this->client($factory)->activate('GRD-KEY', self::HOST, self::NOW);

        $call = $factory->calls[0];
        self::assertSame('https://www.v-t.one/api/v1/verify', $call['url']);
        self::assertSame(ServiceEndpoint::CONNECT_TIMEOUT_SECONDS, $call['connect']);
        self::assertSame(ServiceEndpoint::TOTAL_TIMEOUT_SECONDS, $call['total']);
    }

    #[Test]
    public function anAnswerWithoutTheSignedHostSetIsRefused(): void
    {
        // A freshly issued record must say which hosts it covers. One that does
        // not is correctly signed but grants nothing, so it is refused rather
        // than stored in place of something that works.
        $outcome = $this->correlated([], ['license_domains' => null, 'license_max_domains' => null]);

        self::assertFalse($outcome->isConfirmed());
        self::assertSame('record_domains_missing', $outcome->category);
    }

    #[Test]
    public function anAnswerBoundToAnotherHostIsRefused(): void
    {
        $outcome = $this->correlated([], [
            'license_domain' => 'other.example.com',
            'license_domains' => ['other.example.com'],
        ]);

        self::assertFalse($outcome->isConfirmed());
        self::assertSame('host_binding_mismatch', $outcome->category);
    }

    #[Test]
    public function aTransportFailureIsAnOutageAndNotARefusal(): void
    {
        $outcome = $this->client($this->factory(new \RuntimeException('connection reset')))
            ->activate('GRD-KEY', self::HOST, self::NOW);

        self::assertSame(ProvisioningStatus::Unreachable, $outcome->status);
        self::assertSame('transport_failed', $outcome->category);
    }

    #[Test]
    public function aServerErrorIsAnOutageAndNotARefusal(): void
    {
        foreach ([500, 502, 503] as $status) {
            $outcome = $this->client($this->factory($this->jsonResponse([], $status)))
                ->activate('GRD-KEY', self::HOST, self::NOW);
            self::assertSame(ProvisioningStatus::Unreachable, $outcome->status, (string) $status);
        }
    }

    #[Test]
    public function aRedirectIsTreatedAsAnOutageRatherThanFollowed(): void
    {
        $outcome = $this->client($this->factory($this->jsonResponse([], 302)))
            ->activate('GRD-KEY', self::HOST, self::NOW);

        self::assertSame(ProvisioningStatus::Unreachable, $outcome->status);
    }

    #[Test]
    public function anUnexpectedMediaTypeIsRejected(): void
    {
        $package = $this->vendor->package();
        $outcome = $this->client($this->factory($this->jsonResponse([
            'status' => 'valid',
            'request_id' => 'x',
            'server_time' => self::NOW,
            'license_payload_b64' => $package['payload'],
            'integrity' => $package['envelope'],
        ], 200, 'text/html')))->activate('GRD-KEY', self::HOST, self::NOW);

        self::assertSame(ProvisioningStatus::Rejected, $outcome->status);
        self::assertSame('unexpected_media_type', $outcome->category);
    }

    #[Test]
    public function anAnswerAboutAnotherRequestIsRejected(): void
    {
        $package = $this->vendor->package();
        $outcome = $this->client($this->factory($this->jsonResponse([
            'status' => 'valid',
            'request_id' => 'some-other-request',
            'server_time' => self::NOW,
            'license_payload_b64' => $package['payload'],
            'integrity' => $package['envelope'],
        ])))->activate('GRD-KEY', self::HOST, self::NOW);

        self::assertSame(ProvisioningStatus::Rejected, $outcome->status);
        self::assertSame('response_uncorrelated', $outcome->category);
    }

    #[Test]
    public function anImplausibleServerClockIsRejected(): void
    {
        // Correlate properly so the clock is the only thing wrong.
        $outcome = $this->correlated(['server_time' => self::NOW + 99999]);

        self::assertSame(ProvisioningStatus::Rejected, $outcome->status);
        self::assertSame('response_clock_skew', $outcome->category);
    }

    #[Test]
    public function aRefusalIsReportedAsSuch(): void
    {
        $outcome = $this->correlated(['status' => 'invalid']);

        self::assertSame(ProvisioningStatus::Denied, $outcome->status);
    }

    #[Test]
    public function aPackageBoundToAnotherHostIsRejected(): void
    {
        $outcome = $this->correlated([], ['license_domain' => 'other.example.com']);

        self::assertSame(ProvisioningStatus::Rejected, $outcome->status);
        self::assertSame('host_binding_mismatch', $outcome->category);
    }

    #[Test]
    public function aPackageForAnotherKeyIsRejected(): void
    {
        $outcome = $this->correlated([], ['license_key' => 'SOMEONE-ELSES-KEY']);

        self::assertSame(ProvisioningStatus::Rejected, $outcome->status);
        self::assertSame('key_binding_mismatch', $outcome->category);
    }

    #[Test]
    public function anAnswerOfferingTheFreePackageIsConfirmed(): void
    {
        $outcome = $this->correlated([], ['license_package' => 'free']);

        self::assertSame(ProvisioningStatus::Confirmed, $outcome->status);
        self::assertNotNull($outcome->record);
        self::assertSame('free', $outcome->record->package);
    }

    /**
     * The product is sold as "free" or "pro". An answer offering anything else
     * is refused before it can be stored, so an installation cannot be moved to
     * a package that does not exist here.
     */
    #[Test]
    public function anAnswerOfferingAPackageThisProductDoesNotSellIsRejected(): void
    {
        foreach (['trial', 'starter', 'enterprise'] as $package) {
            $outcome = $this->correlated([], ['license_package' => $package]);

            self::assertSame(ProvisioningStatus::Rejected, $outcome->status, $package);
            self::assertSame('record_invalid_product', $outcome->category, $package);
            self::assertNull($outcome->record, $package);
        }
    }

    #[Test]
    public function aFullyValidAnswerIsConfirmedWithItsExactBytes(): void
    {
        $outcome = $this->correlated();

        self::assertSame(ProvisioningStatus::Confirmed, $outcome->status);
        self::assertNotNull($outcome->record);
        self::assertSame(self::HOST, $outcome->record->host);
        self::assertNotSame('', (string) $outcome->documentBytes);
        self::assertNotSame([], $outcome->envelope);
    }

    #[Test]
    public function anUnresolvedHostIsNeverSentAnywhere(): void
    {
        $factory = $this->factory($this->jsonResponse([]));

        $outcome = $this->client($factory)->activate('GRD-KEY', '', self::NOW);

        self::assertSame([], $factory->calls);
        self::assertSame('host_unresolved', $outcome->category);
    }

    /**
     * Runs one exchange against a double that echoes back whatever identifier
     * the client generated, so correlation succeeds and the assertion under test
     * is the only thing that can fail.
     *
     * @param array<string, mixed> $responseOverrides
     * @param array<string, mixed> $documentOverrides
     */
    private function correlated(array $responseOverrides = [], array $documentOverrides = []): ProvisioningOutcome
    {
        $package = $this->vendor->package($documentOverrides + ['license_domain' => self::HOST]);

        $factory = new class ($package, $responseOverrides) implements ExchangeTransportInterface {
            /**
             * @param array{payload: string, envelope: array<string, mixed>} $package
             * @param array<string, mixed> $overrides
             */
            public function __construct(
                private readonly array $package,
                private readonly array $overrides,
            ) {
            }

            public function post(string $url, array $packet, float $connectTimeout, float $totalTimeout): ResponseInterface
            {
                $body = $this->overrides + [
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

        return $this->client($factory)->activate('GRD-TEST-0001-0002-0003', self::HOST, self::NOW);
    }
}
