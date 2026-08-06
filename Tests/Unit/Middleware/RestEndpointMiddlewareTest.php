<?php

declare(strict_types=1);

/*
 * This file is part of the "Guardian for TYPO3" extension for TYPO3 CMS.
 *
 * @author    V&T Innovations Team
 * @license   LGPL-3.0-or-later
 * @copyright V&T Innovations 2026 - 2028
 */

namespace Vtinnovations\GuardianTypo3\Tests\Unit\Middleware;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\RequestHandlerInterface;
use TYPO3\CMS\Core\Http\NormalizedParams;
use TYPO3\CMS\Core\Http\Response;
use TYPO3\CMS\Core\Http\ServerRequest;
use TYPO3\CMS\Core\Http\Stream;
use Vtinnovations\GuardianTypo3\Application\Configuration\RecordIntake;
use Vtinnovations\GuardianTypo3\Application\Environment\EntitlementReader;
use Vtinnovations\GuardianTypo3\Infrastructure\Configuration\SealedRecordStore;
use Vtinnovations\GuardianTypo3\Infrastructure\Exchange\RequestJournal;
use Vtinnovations\GuardianTypo3\Infrastructure\Manifest\CanonicalForm;
use Vtinnovations\GuardianTypo3\Infrastructure\Manifest\DetachedSignature;
use Vtinnovations\GuardianTypo3\Infrastructure\Registry\ServiceEndpoint;
use Vtinnovations\GuardianTypo3\Middleware\RestEndpointMiddleware;
use Vtinnovations\GuardianTypo3\Tests\Unit\Support\FixedClock;
use Vtinnovations\GuardianTypo3\Tests\Unit\Support\FixedHosts;
use Vtinnovations\GuardianTypo3\Tests\Unit\Support\FixedIdentity;
use Vtinnovations\GuardianTypo3\Tests\Unit\Support\InMemoryLockFactory;
use Vtinnovations\GuardianTypo3\Tests\Unit\Support\RecordPackageFactory;
use Vtinnovations\GuardianTypo3\Tests\Unit\Support\RecordingLogger;
use Vtinnovations\GuardianTypo3\Tests\Unit\Support\TempWorkingDirectory;
use Vtinnovations\GuardianTypo3\Typo3\Authorization\SignedRequestAuthorization;

/**
 * The public endpoint, exercised through the real middleware, the real request
 * authenticator and the real store.
 */
final class RestEndpointMiddlewareTest extends TestCase
{
    private const PATH = '/rest/api/v1/guardian-license-updater';
    private const NOW = 1784882547;
    private const HOST = 'example.com';

    private string $base;
    private RecordPackageFactory $vendor;
    private SealedRecordStore $store;
    private FixedClock $clock;
    private RecordingLogger $logger;
    private FixedHosts $hosts;
    private RestEndpointMiddleware $middleware;

    protected function setUp(): void
    {
        $GLOBALS['TYPO3_CONF_VARS']['SYS']['trustedHostsPattern'] = '.*';
        $this->base = sys_get_temp_dir() . '/guardian-endpoint-' . bin2hex(random_bytes(6));
        $directory = new TempWorkingDirectory($this->base);
        $this->vendor = new RecordPackageFactory();
        $this->clock = new FixedClock(self::NOW);
        $this->logger = new RecordingLogger();

        $sealed = $this->vendor->sealedPackage();
        $locks = new InMemoryLockFactory();
        $this->store = new SealedRecordStore($directory, $sealed, $locks);

        $this->hosts = new FixedHosts(self::HOST);
        $reader = new EntitlementReader($this->store, new FixedIdentity(self::HOST), $this->hosts, $this->clock, null);
        $intake = new RecordIntake(
            $this->store,
            $sealed,
            new RequestJournal($directory, $locks),
            $this->hosts,
            $reader,
            $this->logger,
        );

        $this->middleware = new RestEndpointMiddleware(
            new SignedRequestAuthorization(new CanonicalForm(), new DetachedSignature($this->vendor->keyring())),
            $intake,
            new ServiceEndpoint(),
            $this->clock,
        );
    }

    protected function tearDown(): void
    {
        if (is_dir($this->base)) {
            exec('rm -rf ' . escapeshellarg($this->base));
        }
    }

    private function handler(): RequestHandlerInterface
    {
        return new class () implements RequestHandlerInterface {
            public bool $called = false;

            public function handle(ServerRequestInterface $request): ResponseInterface
            {
                $this->called = true;

                return new Response(null, 200);
            }
        };
    }

    /**
     * @param array<string, mixed> $overrides changes to the record document
     * @return array<string, mixed> the push body
     */
    private function body(array $overrides = [], string $requestId = 'req-0001', string $nonce = 'nonce-0001', int $timestamp = self::NOW): array
    {
        $package = $this->vendor->package($overrides);

        return [
            'action' => 'license_update',
            'project' => 'Guardian',
            'project_slug' => 'guardian',
            'product_id' => 'vt-guardian',
            'domain' => self::HOST,
            'request_id' => $requestId,
            'timestamp' => $timestamp,
            'nonce' => $nonce,
            'license_payload_b64' => $package['payload'],
            'integrity' => $package['envelope'],
        ];
    }

    /**
     * @param array<string, mixed> $body
     */
    private function request(
        array $body,
        string $method = 'POST',
        string $path = self::PATH,
        string $contentType = 'application/json',
        bool $sign = true,
        ?string $rawOverride = null,
    ): ServerRequestInterface {
        $raw = $rawOverride ?? (string) json_encode($body);
        $request = (new ServerRequest('https://' . self::HOST . $path, $method))
            ->withHeader('Content-Type', $contentType)
            ->withHeader('Content-Length', (string) strlen($raw))
            ->withBody(new Stream('php://temp', 'rw'));
        $request->getBody()->write($raw);
        $request->getBody()->rewind();

        $request = $request->withAttribute('normalizedParams', new NormalizedParams(
            ['HTTP_HOST' => self::HOST, 'HTTPS' => 'on', 'REQUEST_URI' => $path, 'SCRIPT_NAME' => '/index.php'],
            $GLOBALS['TYPO3_CONF_VARS']['SYS'] ?? [],
            '',
            ''
        ));

        if ($sign) {
            $request = $request
                ->withHeader(SignedRequestAuthorization::HEADER_REQUEST_ID, (string) $body['request_id'])
                ->withHeader(SignedRequestAuthorization::HEADER_TIMESTAMP, (string) $body['timestamp'])
                ->withHeader(SignedRequestAuthorization::HEADER_NONCE, (string) $body['nonce'])
                ->withHeader(SignedRequestAuthorization::HEADER_KEY_ID, $this->vendor->keyId)
                ->withHeader(SignedRequestAuthorization::HEADER_SIGNATURE, $this->vendor->signRequest(
                    $method,
                    self::PATH,
                    (string) $body['request_id'],
                    (int) $body['timestamp'],
                    (string) $body['nonce'],
                    $raw,
                ));
        }

        return $request;
    }

    /**
     * @return array<string, mixed>
     */
    private function decode(ResponseInterface $response): array
    {
        $response->getBody()->rewind();
        $decoded = json_decode((string) $response->getBody(), true);

        return \is_array($decoded) ? $decoded : [];
    }

    // ── Routing ─────────────────────────────────────────────────────────────

    /**
     * @return list<array{0: string}>
     */
    public static function unrelatedPaths(): array
    {
        return [
            ['/'],
            ['/some/page'],
            ['/rest/api/v1/'],
            ['/rest/api/v1/something-else'],
            ['/rest/api/v1/guardian-license-updater/extra'],
            ['/rest/api/v1/guardian-license-updaterx'],
            ['/prefix/rest/api/v1/guardian-license-updater'],
            ['/typo3/index.php'],
        ];
    }

    #[Test]
    #[DataProvider('unrelatedPaths')]
    public function anUnrelatedPathIsPassedStraightThrough(string $path): void
    {
        $handler = $this->handler();
        $request = $this->request($this->body(), 'GET', $path, 'text/html', false);

        $response = $this->middleware->process($request, $handler);

        self::assertTrue($handler->called, $path);
        self::assertSame(200, $response->getStatusCode());
    }

    #[Test]
    public function theExactPathIsClaimedWithOrWithoutATrailingSlash(): void
    {
        foreach ([self::PATH, self::PATH . '/'] as $path) {
            $handler = $this->handler();
            $this->middleware->process($this->request($this->body(), 'GET', $path, 'application/json', false), $handler);
            self::assertFalse($handler->called, $path);
        }
    }

    // ── Request shape ───────────────────────────────────────────────────────

    #[Test]
    public function aPushReachingASecondConfiguredDomainIsStillRecognised(): void
    {
        // An installation serving two names may be pushed to about either of them.
        $this->hosts->set('example.com', 'shop.example.com');
        $body = $this->body([
            'license_domain' => 'shop.example.com',
            'license_domains' => ['example.com', 'shop.example.com'],
        ]);
        $body['domain'] = 'shop.example.com';

        $response = $this->middleware->process($this->request($body), $this->handler());

        self::assertSame(200, $response->getStatusCode());
        self::assertSame(7, $this->store->read(self::NOW)->record?->version);
    }

    #[Test]
    public function aPushNamingAHostThisInstallationDoesNotServeIsRefused(): void
    {
        // The name is compared against site configuration, so a push carrying a
        // host that was never configured here changes nothing — however correctly
        // it is signed and whatever URL it arrived at.
        $body = $this->body(['license_domain' => 'elsewhere.example.com', 'license_version' => 9]);
        $body['domain'] = 'elsewhere.example.com';

        $response = $this->middleware->process($this->request($body), $this->handler());

        self::assertSame(403, $response->getStatusCode());
        self::assertNull($this->store->read(self::NOW)->record);
    }

    #[Test]
    public function aPushMustNameTheSameHostTheSignedRecordWasIssuedFor(): void
    {
        // Two authenticated statements that disagree: the body says one host, the
        // signed record another. Neither is preferred; the push is refused.
        $body = $this->body(['license_version' => 9]);
        $body['domain'] = 'other.example.com';

        $response = $this->middleware->process($this->request($body), $this->handler());

        self::assertGreaterThanOrEqual(400, $response->getStatusCode());
        self::assertNull($this->store->read(self::NOW)->record);
    }

    #[Test]
    public function aPushCarryingNoHostSetIsRefusedRatherThanStored(): void
    {
        // Storing it would replace a working licence with one that grants nothing.
        $body = $this->body(['license_domains' => null, 'license_max_domains' => null]);

        $response = $this->middleware->process($this->request($body), $this->handler());

        self::assertSame(403, $response->getStatusCode());
        self::assertNull($this->store->read(self::NOW)->record);
    }

    #[Test]
    public function aGetRequestIsAnsweredWithMethodNotAllowedRatherThanNotFound(): void
    {
        $response = $this->middleware->process(
            $this->request($this->body(), 'GET', self::PATH, 'application/json', false),
            $this->handler()
        );

        self::assertSame(405, $response->getStatusCode());
        self::assertSame('POST', $response->getHeaderLine('Allow'));
    }

    #[Test]
    public function anUnsupportedMediaTypeIsRefused(): void
    {
        foreach (['text/plain', 'application/xml', 'application/x-www-form-urlencoded', ''] as $type) {
            $response = $this->middleware->process(
                $this->request($this->body(), 'POST', self::PATH, $type, false),
                $this->handler()
            );
            self::assertSame(415, $response->getStatusCode(), $type);
        }
    }

    #[Test]
    public function aJsonMediaTypeWithParametersIsAccepted(): void
    {
        $body = $this->body();
        $response = $this->middleware->process(
            $this->request($body, 'POST', self::PATH, 'application/json; charset=utf-8'),
            $this->handler()
        );

        self::assertSame(200, $response->getStatusCode());
    }

    #[Test]
    public function anOversizedBodyIsRefusedByItsDeclaredLength(): void
    {
        $request = $this->request($this->body(), 'POST', self::PATH, 'application/json', false)
            ->withHeader('Content-Length', '999999');

        $response = $this->middleware->process($request, $this->handler());

        self::assertSame(413, $response->getStatusCode());
    }

    #[Test]
    public function anOversizedBodyIsRefusedEvenWhenItsLengthIsUndeclared(): void
    {
        $raw = str_repeat('x', 70000);
        $request = $this->request($this->body(), 'POST', self::PATH, 'application/json', false, $raw)
            ->withoutHeader('Content-Length');

        $response = $this->middleware->process($request, $this->handler());

        self::assertSame(413, $response->getStatusCode());
    }

    #[Test]
    public function aBodyThatIsNotAJsonObjectIsRefused(): void
    {
        foreach (['not json', '[]', '"a string"', '123', 'null'] as $raw) {
            $response = $this->middleware->process(
                $this->request($this->body(), 'POST', self::PATH, 'application/json', false, $raw),
                $this->handler()
            );
            self::assertSame(400, $response->getStatusCode(), $raw);
        }
    }

    // ── Authentication ──────────────────────────────────────────────────────

    #[Test]
    public function anUnsignedRequestIsRefusedAndChangesNothing(): void
    {
        $response = $this->middleware->process(
            $this->request($this->body(), 'POST', self::PATH, 'application/json', false),
            $this->handler()
        );

        self::assertSame(401, $response->getStatusCode());
        self::assertSame(['status' => 'error'], $this->decode($response));
        self::assertFalse($this->store->read(self::NOW)->exists());
    }

    #[Test]
    public function aRefusalNeverRevealsWhichCheckFailed(): void
    {
        $unsigned = $this->decode($this->middleware->process(
            $this->request($this->body(), 'POST', self::PATH, 'application/json', false),
            $this->handler()
        ));
        $tampered = $this->request($this->body())
            ->withHeader(SignedRequestAuthorization::HEADER_SIGNATURE, base64_encode(str_repeat("\x00", 64)));
        $badSignature = $this->decode($this->middleware->process($tampered, $this->handler()));

        self::assertSame(['status' => 'error'], $unsigned);
        self::assertSame(['status' => 'error'], $badSignature);
    }

    #[Test]
    public function aSignatureThatDoesNotCoverThisBodyIsRefused(): void
    {
        $body = $this->body();
        $request = $this->request($body);
        // Same signature, different body.
        $altered = json_encode(['action' => 'license_update'] + $body + ['extra' => 'field']);
        self::assertIsString($altered);
        $request->getBody()->rewind();
        $stream = new Stream('php://temp', 'rw');
        $stream->write($altered);
        $stream->rewind();

        $response = $this->middleware->process($request->withBody($stream), $this->handler());

        self::assertSame(401, $response->getStatusCode());
    }

    #[Test]
    public function aSignatureMintedForAnotherPathIsRefused(): void
    {
        $body = $this->body();
        $raw = (string) json_encode($body);
        $request = $this->request($body, 'POST', self::PATH, 'application/json', false)
            ->withHeader(SignedRequestAuthorization::HEADER_REQUEST_ID, 'req-0001')
            ->withHeader(SignedRequestAuthorization::HEADER_TIMESTAMP, (string) self::NOW)
            ->withHeader(SignedRequestAuthorization::HEADER_NONCE, 'nonce-0001')
            ->withHeader(SignedRequestAuthorization::HEADER_KEY_ID, $this->vendor->keyId)
            ->withHeader(SignedRequestAuthorization::HEADER_SIGNATURE, $this->vendor->signRequest(
                'POST',
                '/rest/api/v1/other-license-updater',
                'req-0001',
                self::NOW,
                'nonce-0001',
                $raw,
            ));

        self::assertSame(401, $this->middleware->process($request, $this->handler())->getStatusCode());
    }

    #[Test]
    public function headersThatDisagreeWithTheBodyAreRefused(): void
    {
        $body = $this->body();
        $request = $this->request($body)
            ->withHeader(SignedRequestAuthorization::HEADER_REQUEST_ID, 'req-9999');

        self::assertSame(401, $this->middleware->process($request, $this->handler())->getStatusCode());
    }

    #[Test]
    public function aStaleOrFutureRequestIsRefused(): void
    {
        foreach ([self::NOW - 600, self::NOW + 600] as $timestamp) {
            $body = $this->body([], 'req-' . $timestamp, 'nonce-' . $timestamp, $timestamp);
            $response = $this->middleware->process($this->request($body), $this->handler());
            self::assertSame(401, $response->getStatusCode(), (string) $timestamp);
        }
    }

    #[Test]
    public function aRequestWithinTheClockWindowIsAccepted(): void
    {
        $body = $this->body([], 'req-skew', 'nonce-skew', self::NOW - 120);

        self::assertSame(200, $this->middleware->process($this->request($body), $this->handler())->getStatusCode());
    }

    #[Test]
    public function anUnknownKeyIdentifierIsRefused(): void
    {
        $body = $this->body();
        $request = $this->request($body)
            ->withHeader(SignedRequestAuthorization::HEADER_KEY_ID, 'vtone-9999z');

        self::assertSame(401, $this->middleware->process($request, $this->handler())->getStatusCode());
    }

    // ── Applying the record ─────────────────────────────────────────────────

    #[Test]
    public function aValidPushIsApplied(): void
    {
        $response = $this->middleware->process($this->request($this->body()), $this->handler());

        self::assertSame(200, $response->getStatusCode());
        self::assertSame(
            ['status' => 'updated', 'request_id' => 'req-0001', 'license_version' => 7],
            $this->decode($response)
        );
        self::assertSame(7, $this->store->read(self::NOW)->record->version);
    }

    #[Test]
    public function anExactRepeatIsIdempotent(): void
    {
        $body = $this->body();
        $first = $this->middleware->process($this->request($body), $this->handler());
        $second = $this->middleware->process($this->request($body), $this->handler());

        self::assertSame('updated', $this->decode($first)['status']);
        self::assertSame(
            ['status' => 'already_processed', 'request_id' => 'req-0001', 'license_version' => 7],
            $this->decode($second)
        );
        self::assertSame(7, $this->store->read(self::NOW)->record->version);
    }

    #[Test]
    public function theSameIdentifierCarryingDifferentContentIsRefused(): void
    {
        $this->middleware->process($this->request($this->body(['license_version' => 7])), $this->handler());

        // Same request id, different signed content.
        $conflicting = $this->body(['license_version' => 9], 'req-0001', 'nonce-0002');
        $response = $this->middleware->process($this->request($conflicting), $this->handler());

        self::assertSame(409, $response->getStatusCode());
        self::assertSame(7, $this->store->read(self::NOW)->record->version);
    }

    #[Test]
    public function aReusedOneTimeValueIsRefused(): void
    {
        $this->middleware->process($this->request($this->body([], 'req-0001', 'shared-nonce')), $this->handler());

        $replay = $this->body(['license_version' => 9], 'req-0002', 'shared-nonce');
        $response = $this->middleware->process($this->request($replay), $this->handler());

        self::assertSame(401, $response->getStatusCode());
        self::assertSame(7, $this->store->read(self::NOW)->record->version);
    }

    #[Test]
    public function anOlderVersionCannotRollTheInstallationBack(): void
    {
        $this->middleware->process($this->request($this->body(['license_version' => 9])), $this->handler());

        $older = $this->body(['license_version' => 7], 'req-0002', 'nonce-0002');
        $response = $this->middleware->process($this->request($older), $this->handler());

        self::assertSame(409, $response->getStatusCode());
        self::assertSame(9, $this->store->read(self::NOW)->record->version);
    }

    #[Test]
    public function anIdenticalVersionIsNotReapplied(): void
    {
        $this->middleware->process($this->request($this->body(['license_version' => 7])), $this->handler());

        $same = $this->body(['license_version' => 7], 'req-0002', 'nonce-0002');
        self::assertSame(409, $this->middleware->process($this->request($same), $this->handler())->getStatusCode());
    }

    #[Test]
    public function aPushForAnotherHostIsRefused(): void
    {
        $body = $this->body(['license_domain' => 'other.example.com']);
        $body['domain'] = 'other.example.com';
        // Re-sign, because the body changed.
        $response = $this->middleware->process($this->request($body), $this->handler());

        self::assertSame(403, $response->getStatusCode());
        self::assertFalse($this->store->read(self::NOW)->exists());
    }

    #[Test]
    public function aPushWhoseBodyAndRecordDisagreeAboutTheHostIsRefused(): void
    {
        $body = $this->body(['license_domain' => 'shop.example.com']);
        // The body claims this installation, the signed record names another host.
        $response = $this->middleware->process($this->request($body), $this->handler());

        self::assertSame(403, $response->getStatusCode());
        self::assertFalse($this->store->read(self::NOW)->exists());
    }

    #[Test]
    public function aPushForAnotherProductIsRefused(): void
    {
        foreach (['project' => 'Brickie', 'project_slug' => 'brickie', 'product_id' => 'vt-brickie', 'action' => 'something'] as $field => $value) {
            $body = $this->body([], 'req-' . $field, 'nonce-' . $field);
            $body[$field] = $value;
            $response = $this->middleware->process($this->request($body), $this->handler());
            self::assertSame(403, $response->getStatusCode(), $field);
        }
        self::assertFalse($this->store->read(self::NOW)->exists());
    }

    #[Test]
    public function aPushCarryingAnUnverifiablePackageIsRefusedWithoutTouchingTheStore(): void
    {
        $package = $this->vendor->package();
        $body = $this->body();
        // A digest that no longer matches the payload.
        $envelope = $package['envelope'];
        $envelope['license_md5'] = hash('md5', 'different');
        $body['integrity'] = $envelope;

        $response = $this->middleware->process($this->request($body), $this->handler());

        self::assertSame(403, $response->getStatusCode());
        self::assertFalse($this->store->read(self::NOW)->exists());
    }

    #[Test]
    public function aPushThatChangesTheActivatedKeyIsRefused(): void
    {
        $this->middleware->process($this->request($this->body()), $this->handler());

        $swapped = $this->body(
            ['license_version' => 9, 'license_key' => 'GRD-SOMEONE-ELSES-KEY'],
            'req-0002',
            'nonce-0002'
        );
        $response = $this->middleware->process($this->request($swapped), $this->handler());

        self::assertSame(403, $response->getStatusCode());
        self::assertSame('GRD-TEST-0001-0002-0003', $this->store->read(self::NOW)->record->key);
    }

    #[Test]
    public function aRefusedPushCanBeRetriedWithACorrectedPackage(): void
    {
        $broken = $this->body(['license_domain' => 'shop.example.com']);
        self::assertSame(403, $this->middleware->process($this->request($broken), $this->handler())->getStatusCode());

        // Same identifier, corrected content: the reservation was released.
        $fixed = $this->body([], 'req-0001', 'nonce-0002');
        self::assertSame(200, $this->middleware->process($this->request($fixed), $this->handler())->getStatusCode());
    }
}
