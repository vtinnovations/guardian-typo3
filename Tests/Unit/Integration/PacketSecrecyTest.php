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
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\RequestHandlerInterface;
use TYPO3\CMS\Core\Http\NormalizedParams;
use TYPO3\CMS\Core\Http\Response;
use TYPO3\CMS\Core\Http\ServerRequest;
use TYPO3\CMS\Core\Http\Stream;
use Vtinnovations\GuardianTypo3\Application\Configuration\ActivationService;
use Vtinnovations\GuardianTypo3\Application\Configuration\RecordIntake;
use Vtinnovations\GuardianTypo3\Application\Environment\EntitlementReader;
use Vtinnovations\GuardianTypo3\Domain\Configuration\ProvisioningOutcome;
use Vtinnovations\GuardianTypo3\Infrastructure\Configuration\SealedRecordStore;
use Vtinnovations\GuardianTypo3\Infrastructure\Exchange\RequestJournal;
use Vtinnovations\GuardianTypo3\Infrastructure\Manifest\CanonicalForm;
use Vtinnovations\GuardianTypo3\Infrastructure\Manifest\DetachedSignature;
use Vtinnovations\GuardianTypo3\Infrastructure\Registry\EntryNotice;
use Vtinnovations\GuardianTypo3\Infrastructure\Registry\ServiceEndpoint;
use Vtinnovations\GuardianTypo3\Middleware\RestEndpointMiddleware;
use Vtinnovations\GuardianTypo3\Tests\Unit\Support\FixedClock;
use Vtinnovations\GuardianTypo3\Tests\Unit\Support\FixedHosts;
use Vtinnovations\GuardianTypo3\Tests\Unit\Support\FixedIdentity;
use Vtinnovations\GuardianTypo3\Tests\Unit\Support\InMemoryLockFactory;
use Vtinnovations\GuardianTypo3\Tests\Unit\Support\InMemorySessionClaim;
use Vtinnovations\GuardianTypo3\Tests\Unit\Support\RecordPackageFactory;
use Vtinnovations\GuardianTypo3\Tests\Unit\Support\RecordingLogger;
use Vtinnovations\GuardianTypo3\Tests\Unit\Support\RecordingPingTransport;
use Vtinnovations\GuardianTypo3\Tests\Unit\Support\ScriptedExchange;
use Vtinnovations\GuardianTypo3\Tests\Unit\Support\TempWorkingDirectory;
use Vtinnovations\GuardianTypo3\Typo3\Authorization\SignedRequestAuthorization;

/**
 * What must never reach the audit trail or a browser.
 *
 * Two complementary checks. The first drives the real flows — successful and
 * failing, outbound and inbound — with a logger that captures everything, and
 * asserts the sensitive material is absent from what was captured. The second
 * reads the source itself, because a value that is never logged today can start
 * being logged tomorrow, and the point of these rules is that they hold.
 */
final class PacketSecrecyTest extends TestCase
{
    private const NOW = 1784882547;
    private const HOST = 'example.com';
    private const KEY = 'GRD-VERY-SECRET-KEY-0001';

    private string $base;
    private RecordPackageFactory $vendor;
    private SealedRecordStore $store;
    private RecordingLogger $logger;
    private FixedClock $clock;

    protected function setUp(): void
    {
        $GLOBALS['TYPO3_CONF_VARS']['SYS']['trustedHostsPattern'] = '.*';
        $this->base = sys_get_temp_dir() . '/guardian-secrecy-' . bin2hex(random_bytes(6));
        $this->vendor = new RecordPackageFactory();
        $this->clock = new FixedClock(self::NOW);
        $this->logger = new RecordingLogger();
        $this->store = new SealedRecordStore(
            new TempWorkingDirectory($this->base),
            $this->vendor->sealedPackage(),
            new InMemoryLockFactory(),
        );
    }

    protected function tearDown(): void
    {
        if (is_dir($this->base)) {
            exec('rm -rf ' . escapeshellarg($this->base));
        }
    }

    /**
     * The values that must never appear, and a label for each so a failure says
     * what leaked.
     *
     * @param array{payload: string, envelope: array<string, mixed>, bytes: string} $package
     * @return array<string, string>
     */
    private function forbiddenValues(array $package): array
    {
        return [
            'the full licence key' => self::KEY,
            'the Base64 payload' => $package['payload'],
            'the raw document bytes' => $package['bytes'],
            'the exact-byte digest' => (string) $package['envelope']['license_md5'],
            'the envelope signature' => (string) $package['envelope']['signature'],
            'a one-time value' => 'nonce-secret-0001',
            'a digest of the licence key' => hash('sha256', self::KEY),
        ];
    }

    /**
     * @param array{payload: string, envelope: array<string, mixed>, bytes: string} $package
     */
    private function assertTranscriptIsClean(array $package): void
    {
        $transcript = $this->logger->transcript();
        self::assertNotSame('', $transcript, 'the flow should have logged something');

        foreach ($this->forbiddenValues($package) as $label => $value) {
            self::assertStringNotContainsString($value, $transcript, $label . ' leaked into the audit trail');
        }

        // A field name is as much of a leak as the value, because it signals the
        // value is being carried.
        foreach ([
            'license_payload_b64',
            'license_md5',
            'request_packet',
            'response_packet',
            'request_body',
            'response_body',
            'request_sha256',
            'response_sha256',
            'licence_key_sha256',
            'license_key_sha256',
            'licence_key_length',
            'license_key_length',
            'X-VT-Signature',
            'X-VT-Nonce',
        ] as $field) {
            self::assertStringNotContainsString($field, $transcript, $field . ' appeared in the audit trail');
        }
    }

    #[Test]
    public function aSuccessfulActivationLogsNothingSensitive(): void
    {
        $package = $this->vendor->package(['license_key' => self::KEY]);
        $record = $this->vendor->sealedPackage()->open($package['payload'], $package['envelope'], self::NOW)->record;
        self::assertNotNull($record);

        $hosts = new FixedHosts(self::HOST);
        $reader = new EntitlementReader($this->store, new FixedIdentity(self::HOST), $hosts, $this->clock, null);
        $service = new ActivationService(
            $this->store,
            new ScriptedExchange(ProvisioningOutcome::confirmed($record, $package['bytes'], $package['envelope'])),
            new FixedIdentity(self::HOST),
            $hosts,
            $reader,
            $this->clock,
            $this->logger,
        );

        self::assertTrue($service->activate(self::KEY)->isPro());
        $this->assertTranscriptIsClean($package);
    }

    #[Test]
    public function aFailedActivationLogsNothingSensitive(): void
    {
        $package = $this->vendor->package(['license_key' => self::KEY]);
        $hosts = new FixedHosts(self::HOST);
        $reader = new EntitlementReader($this->store, new FixedIdentity(self::HOST), $hosts, $this->clock, null);
        $service = new ActivationService(
            $this->store,
            new ScriptedExchange(
                ProvisioningOutcome::denied('rejected'),
                ProvisioningOutcome::unreachable('transport_failed', 'no route'),
                ProvisioningOutcome::rejected('record_signature_invalid', 'bad'),
            ),
            new FixedIdentity(self::HOST),
            $hosts,
            $reader,
            $this->clock,
            $this->logger,
        );

        $service->activate(self::KEY);
        $service->activate(self::KEY);
        $service->activate(self::KEY);

        $this->assertTranscriptIsClean($package);
    }

    #[Test]
    public function anInboundPushLogsNothingSensitiveWhetherItSucceedsOrFails(): void
    {
        $package = $this->vendor->package(['license_key' => self::KEY]);
        $middleware = $this->middleware();

        // A valid push, an exact repeat, and a refused one.
        $middleware->process($this->push($package, 'req-a', 'nonce-secret-0001'), $this->handler());
        $middleware->process($this->push($package, 'req-a', 'nonce-secret-0001'), $this->handler());
        $middleware->process($this->push($package, 'req-b', 'nonce-secret-0001'), $this->handler());
        $middleware->process($this->push($package, 'req-c', 'nonce-c', false), $this->handler());

        $this->assertTranscriptIsClean($package);
    }

    #[Test]
    public function theEndpointNeverReturnsPacketMaterialToTheCaller(): void
    {
        $package = $this->vendor->package(['license_key' => self::KEY]);
        $middleware = $this->middleware();

        foreach ([
            $this->push($package, 'req-a', 'nonce-a'),
            $this->push($package, 'req-b', 'nonce-b', false),
        ] as $request) {
            $response = $middleware->process($request, $this->handler());
            $response->getBody()->rewind();
            $body = (string) $response->getBody();

            foreach ($this->forbiddenValues($package) as $label => $value) {
                self::assertStringNotContainsString($value, $body, $label . ' was returned to the caller');
            }
        }
    }

    #[Test]
    public function theSessionEntryEventKeepsItsKeyOffEverySurfaceButTheWire(): void
    {
        // The one event that carries a full key. It goes server-to-server and
        // nowhere else: not into the audit trail, not into the session mark, not
        // into anything the browser is given.
        $package = $this->vendor->package(['license_key' => self::KEY]);
        $this->store->replace($package['bytes'], $package['envelope'], self::NOW);

        $transport = new RecordingPingTransport();
        $session = new InMemorySessionClaim();
        $reader = new EntitlementReader(
            $this->store,
            new FixedIdentity(self::HOST),
            new FixedHosts(self::HOST),
            $this->clock,
            null,
        );
        $grant = $reader->grant();

        (new EntryNotice($transport, new ServiceEndpoint(), $session, true))->arm($grant, 'guardian');

        // It did go out, exactly once, carrying the key.
        self::assertCount(1, $transport->sent);
        self::assertStringContainsString(self::KEY, $transport->sent[0]['body']);

        // And nowhere else.
        self::assertStringNotContainsString(self::KEY, $this->logger->transcript());
        self::assertStringNotContainsString(self::KEY, (string) json_encode($session->granted));
        self::assertStringNotContainsString(self::KEY, (string) json_encode($grant->toPublicArray()));
        self::assertStringNotContainsString(
            self::KEY,
            (string) json_encode($grant->toPublicArray()['key_preview']),
            'the administrator projection shows a masked preview only'
        );
    }

    #[Test]
    public function noSourceFileLogsAProhibitedField(): void
    {
        $prohibited = [
            'license_payload_b64',
            'license_md5',
            'request_packet',
            'response_packet',
            'request_sha256',
            'response_sha256',
            'licence_key_sha256',
            'license_key_sha256',
            'licence_key_length',
            'license_key_length',
        ];

        $offenders = [];
        foreach ($this->sourceFiles() as $file) {
            $source = (string) file_get_contents($file);
            foreach ($this->loggingStatements($source) as $statement) {
                foreach ($prohibited as $field) {
                    if (str_contains($statement, $field)) {
                        $offenders[] = basename($file) . ': ' . $field;
                    }
                }
            }
        }

        self::assertSame([], $offenders, 'a logging call references a prohibited field');
    }

    #[Test]
    public function noSourceFileEmbedsAPrivateKeyOrASharedSecret(): void
    {
        $offenders = [];
        foreach ($this->sourceFiles() as $file) {
            $source = (string) file_get_contents($file);
            foreach ([
                'BEGIN PRIVATE KEY',
                'BEGIN RSA PRIVATE KEY',
                'BEGIN OPENSSH PRIVATE KEY',
                'sodium_crypto_sign_detached',
                'sodium_crypto_sign_secretkey',
                'X-VT-Api-Key',
            ] as $marker) {
                if (str_contains($source, $marker)) {
                    $offenders[] = basename($file) . ': ' . $marker;
                }
            }
        }

        self::assertSame([], $offenders, 'the distributed source must contain no signing capability or shared secret');
    }

    // ── plumbing ────────────────────────────────────────────────────────────

    private function middleware(): RestEndpointMiddleware
    {
        $directory = new TempWorkingDirectory($this->base);
        $locks = new InMemoryLockFactory();
        $sealed = $this->vendor->sealedPackage();
        $hosts = new FixedHosts(self::HOST);
        $reader = new EntitlementReader($this->store, new FixedIdentity(self::HOST), $hosts, $this->clock, null);

        return new RestEndpointMiddleware(
            new SignedRequestAuthorization(new CanonicalForm(), new DetachedSignature($this->vendor->keyring())),
            new RecordIntake($this->store, $sealed, new RequestJournal($directory, $locks), $hosts, $reader, $this->logger),
            new ServiceEndpoint(),
            $this->clock,
        );
    }

    /**
     * @param array{payload: string, envelope: array<string, mixed>} $package
     */
    private function push(array $package, string $requestId, string $nonce, bool $sign = true): ServerRequestInterface
    {
        $path = (new ServiceEndpoint())->inboundPath();
        $body = [
            'action' => 'license_update',
            'project' => 'Guardian',
            'project_slug' => 'guardian',
            'product_id' => 'vt-guardian',
            'domain' => self::HOST,
            'request_id' => $requestId,
            'timestamp' => self::NOW,
            'nonce' => $nonce,
            'license_payload_b64' => $package['payload'],
            'integrity' => $package['envelope'],
        ];
        $raw = (string) json_encode($body);

        $request = (new ServerRequest('https://' . self::HOST . $path, 'POST'))
            ->withHeader('Content-Type', 'application/json')
            ->withBody(new Stream('php://temp', 'rw'));
        $request->getBody()->write($raw);
        $request->getBody()->rewind();
        $request = $request->withAttribute('normalizedParams', new NormalizedParams(
            ['HTTP_HOST' => self::HOST, 'HTTPS' => 'on', 'REQUEST_URI' => $path, 'SCRIPT_NAME' => '/index.php'],
            $GLOBALS['TYPO3_CONF_VARS']['SYS'] ?? [],
            '',
            ''
        ));

        if (!$sign) {
            return $request;
        }

        return $request
            ->withHeader(SignedRequestAuthorization::HEADER_REQUEST_ID, $requestId)
            ->withHeader(SignedRequestAuthorization::HEADER_TIMESTAMP, (string) self::NOW)
            ->withHeader(SignedRequestAuthorization::HEADER_NONCE, $nonce)
            ->withHeader(SignedRequestAuthorization::HEADER_KEY_ID, $this->vendor->keyId)
            ->withHeader(SignedRequestAuthorization::HEADER_SIGNATURE, $this->vendor->signRequest(
                'POST',
                $path,
                $requestId,
                self::NOW,
                $nonce,
                $raw,
            ));
    }

    private function handler(): RequestHandlerInterface
    {
        return new class () implements RequestHandlerInterface {
            public function handle(ServerRequestInterface $request): ResponseInterface
            {
                return new Response(null, 200);
            }
        };
    }

    /**
     * @return list<string>
     */
    private function sourceFiles(): array
    {
        $root = \dirname(__DIR__, 3) . '/Classes';
        $files = [];
        $iterator = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($root));
        foreach ($iterator as $file) {
            if ($file instanceof \SplFileInfo && $file->getExtension() === 'php') {
                $files[] = $file->getPathname();
            }
        }
        sort($files);

        return $files;
    }

    /**
     * Extracts the argument text of every logging call, so the check looks at
     * what is actually written rather than at any mention of a name.
     *
     * @return list<string>
     */
    private function loggingStatements(string $source): array
    {
        preg_match_all(
            '/->(?:info|warning|error|debug|notice|critical|alert|emergency|log)\s*\((.*?)\);/s',
            $source,
            $matches
        );

        return $matches[1];
    }
}
