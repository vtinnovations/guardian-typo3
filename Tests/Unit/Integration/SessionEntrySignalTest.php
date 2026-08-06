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
use Vtinnovations\GuardianTypo3\Application\Environment\EntitlementReader;
use Vtinnovations\GuardianTypo3\Domain\Environment\CapabilityGrant;
use Vtinnovations\GuardianTypo3\Infrastructure\Configuration\SealedRecordStore;
use Vtinnovations\GuardianTypo3\Infrastructure\Registry\EntryNotice;
use Vtinnovations\GuardianTypo3\Infrastructure\Registry\ServiceEndpoint;
use Vtinnovations\GuardianTypo3\Tests\Unit\Support\FixedClock;
use Vtinnovations\GuardianTypo3\Tests\Unit\Support\FixedHosts;
use Vtinnovations\GuardianTypo3\Tests\Unit\Support\FixedIdentity;
use Vtinnovations\GuardianTypo3\Tests\Unit\Support\InMemoryLockFactory;
use Vtinnovations\GuardianTypo3\Tests\Unit\Support\InMemorySessionClaim;
use Vtinnovations\GuardianTypo3\Tests\Unit\Support\RecordingPingTransport;
use Vtinnovations\GuardianTypo3\Tests\Unit\Support\RecordPackageFactory;
use Vtinnovations\GuardianTypo3\Tests\Unit\Support\TempWorkingDirectory;

/**
 * The once-per-session notice sent when an administrator opens the module.
 *
 * This is the only place in the product where a full activation key leaves the
 * server, so the tests are about exactly two things: that it happens once, and
 * that it never happens with a key from anywhere but a record that has just
 * verified against the vendor's signature.
 */
final class SessionEntrySignalTest extends TestCase
{
    private const NOW = 1784880600;
    private const HOST = 'example.com';
    private const KEY = 'GRD-LIVE-7788-9900-1122';

    private string $base;
    private RecordPackageFactory $vendor;
    private SealedRecordStore $store;
    private FixedClock $clock;
    private RecordingPingTransport $transport;
    private InMemorySessionClaim $session;

    protected function setUp(): void
    {
        $this->base = sys_get_temp_dir() . '/guardian-entry-' . bin2hex(random_bytes(6));
        $this->vendor = new RecordPackageFactory();
        $this->store = new SealedRecordStore(
            new TempWorkingDirectory($this->base),
            $this->vendor->sealedPackage(),
            new InMemoryLockFactory(),
        );
        $this->clock = new FixedClock(self::NOW);
        $this->transport = new RecordingPingTransport();
        $this->session = new InMemorySessionClaim();
    }

    protected function tearDown(): void
    {
        if (is_dir($this->base)) {
            exec('rm -rf ' . escapeshellarg($this->base));
        }
    }

    /**
     * @param array<string, mixed> $overrides
     */
    private function storeRecord(array $overrides = []): void
    {
        $package = $this->vendor->package(['license_key' => self::KEY] + $overrides);
        $this->store->replace($package['bytes'], $package['envelope'], self::NOW);
    }

    private function notice(): EntryNotice
    {
        return new EntryNotice($this->transport, new ServiceEndpoint(), $this->session, true);
    }

    private function grant(string $configured = self::HOST): CapabilityGrant
    {
        $reader = new EntitlementReader(
            $this->store,
            new FixedIdentity(self::HOST),
            new FixedHosts($configured),
            $this->clock,
            null,
        );

        return $reader->grant();
    }

    /**
     * @return array<string, mixed>
     */
    private function lastBody(): array
    {
        self::assertNotSame([], $this->transport->sent);

        return (array) json_decode($this->transport->sent[\count($this->transport->sent) - 1]['body'], true);
    }

    #[Test]
    public function openingTheModuleSendsExactlyTheHostAndTheKey(): void
    {
        $this->storeRecord();

        $this->notice()->arm($this->grant(), 'guardian');

        self::assertCount(1, $this->transport->sent);
        self::assertSame('https://www.v-t.one/rest/api/v1/log-envoke', $this->transport->sent[0]['url']);
        // Exactly two fields, in the documented order, and nothing else — no
        // project, no version, no user, no session, no packet.
        self::assertSame(['domain' => self::HOST, 'key' => self::KEY], $this->lastBody());
        self::assertSame('{"domain":"example.com","key":"' . self::KEY . '"}', $this->transport->sent[0]['body']);
    }

    #[Test]
    public function reloadsNavigationAndParallelTabsInOneSessionSendNothingFurther(): void
    {
        $this->storeRecord();
        $notice = $this->notice();

        // A reload, a second tab, an AJAX call, a freshly built object graph.
        $notice->arm($this->grant(), 'guardian');
        $notice->arm($this->grant(), 'guardian');
        $this->notice()->arm($this->grant(), 'guardian');

        self::assertCount(1, $this->transport->sent);
    }

    #[Test]
    public function aNewSignInMaySendOnceAgain(): void
    {
        $this->storeRecord();

        $this->notice()->arm($this->grant(), 'guardian');
        $this->session->newSession();
        $this->notice()->arm($this->grant(), 'guardian');

        self::assertCount(2, $this->transport->sent);
    }

    #[Test]
    public function eachProductClaimsSeparatelyOnTheSharedScreen(): void
    {
        // Two sections rendered in one session produce one event each, and still
        // only one each however often the screen is reloaded.
        $this->storeRecord();
        $notice = $this->notice();

        $notice->arm($this->grant(), 'guardian');
        $notice->arm($this->grant(), 'brickie');
        $notice->arm($this->grant(), 'guardian');
        $notice->arm($this->grant(), 'brickie');

        self::assertCount(2, $this->transport->sent);
        self::assertSame(['guardian', 'brickie'], $this->session->granted);
    }

    #[Test]
    public function withNoSignedInSessionNothingIsSent(): void
    {
        // A console command, a queue worker, a frontend request: no session to
        // claim within, so no event.
        $this->storeRecord();
        $this->session->signOut();

        $this->notice()->arm($this->grant(), 'guardian');

        self::assertSame([], $this->transport->sent);
    }

    #[Test]
    public function aTamperedRecordYieldsNoKeyAndThereforeNoEvent(): void
    {
        $this->storeRecord();
        file_put_contents($this->base . '/license.json', '{"license_key":"GRD-FORGED-0000"}');

        $this->notice()->arm($this->grant(), 'guardian');

        self::assertSame([], $this->transport->sent, 'the key may only come from a record that verified');
        self::assertSame([], $this->session->granted, 'and an unsent event must not be marked as claimed');
    }

    #[Test]
    public function withNothingStoredNothingIsSent(): void
    {
        $this->notice()->arm($this->grant(), 'guardian');

        self::assertSame([], $this->transport->sent);
    }

    #[Test]
    public function anAuthenticRecordWhoseEntitlementIsWithheldStillAnnouncesItself(): void
    {
        // Expired, or not for this domain: the key is genuinely the one the vendor
        // issued, and the vendor is the party that should hear about it.
        $this->storeRecord(['free_available' => false]);
        $this->clock->set(1815536001);
        $expired = $this->grant();
        self::assertFalse($expired->isLicensed());

        $this->notice()->arm($expired, 'guardian');

        self::assertSame(['domain' => self::HOST, 'key' => self::KEY], $this->lastBody());
    }

    #[Test]
    public function theDomainIsTheSettledOneRatherThanWhicheverHostIsBeingServed(): void
    {
        $this->storeRecord([
            'license_domain' => 'shop.example.com',
            'license_domains' => ['shop.example.com'],
        ]);

        // Configured for two names, being served as the one the vendor did not
        // authorise. The settled answer is the authorised one.
        $reader = new EntitlementReader(
            $this->store,
            new FixedIdentity('blog.example.com'),
            new FixedHosts('blog.example.com', 'shop.example.com'),
            $this->clock,
            null,
        );

        $this->notice()->arm($reader->grant(), 'guardian');

        self::assertSame('shop.example.com', $this->lastBody()['domain']);
    }

    #[Test]
    public function aTransportFailureIsNotRetriedWithinTheSameSession(): void
    {
        $this->storeRecord();
        $failing = new class implements \Vtinnovations\GuardianTypo3\Infrastructure\Registry\Transport\PingTransportInterface {
            public int $attempts = 0;

            public function send(string $url, string $jsonBody): void
            {
                $this->attempts++;
                throw new \RuntimeException('the endpoint timed out');
            }
        };
        $notice = new EntryNotice($failing, new ServiceEndpoint(), $this->session, true);

        $notice->arm($this->grant(), 'guardian');
        $notice->arm($this->grant(), 'guardian');

        self::assertSame(1, $failing->attempts, 'the claim is taken before delivery, so a failure is final');
    }

    #[Test]
    public function theClaimNeverRecordsTheKeyTheHostOrThePayload(): void
    {
        $this->storeRecord();

        $this->notice()->arm($this->grant(), 'guardian');

        $marker = json_encode($this->session->granted);
        self::assertSame('["guardian"]', $marker);
        self::assertStringNotContainsString(self::KEY, (string) $marker);
        self::assertStringNotContainsString(self::HOST, (string) $marker);
    }
}
