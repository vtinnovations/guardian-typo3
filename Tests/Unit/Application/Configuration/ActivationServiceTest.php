<?php

declare(strict_types=1);

/*
 * This file is part of the "Guardian for TYPO3" extension for TYPO3 CMS.
 *
 * @author    V&T Innovations Team
 * @license   LGPL-3.0-or-later
 * @copyright V&T Innovations 2026 - 2028
 */

namespace Vtinnovations\GuardianTypo3\Tests\Unit\Application\Configuration;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Vtinnovations\GuardianTypo3\Application\Configuration\ActivationService;
use Vtinnovations\GuardianTypo3\Application\Environment\EntitlementReader;
use Vtinnovations\GuardianTypo3\Domain\Configuration\ProvisioningOutcome;
use Vtinnovations\GuardianTypo3\Infrastructure\Configuration\SealedRecordStore;
use Vtinnovations\GuardianTypo3\Tests\Unit\Support\FixedClock;
use Vtinnovations\GuardianTypo3\Tests\Unit\Support\FixedHosts;
use Vtinnovations\GuardianTypo3\Tests\Unit\Support\FixedIdentity;
use Vtinnovations\GuardianTypo3\Tests\Unit\Support\InMemoryLockFactory;
use Vtinnovations\GuardianTypo3\Tests\Unit\Support\RecordPackageFactory;
use Vtinnovations\GuardianTypo3\Tests\Unit\Support\RecordingLogger;
use Vtinnovations\GuardianTypo3\Tests\Unit\Support\ScriptedExchange;
use Vtinnovations\GuardianTypo3\Tests\Unit\Support\TempWorkingDirectory;

/**
 * The two administrator-initiated flows.
 *
 * The recurring theme is that a failure must never cost an installation the
 * entitlement it already has.
 */
final class ActivationServiceTest extends TestCase
{
    private const NOW = 1784880600;

    private string $base;
    private RecordPackageFactory $vendor;
    private SealedRecordStore $store;
    private FixedIdentity $identity;
    private FixedHosts $hosts;
    private FixedClock $clock;
    private RecordingLogger $logger;

    protected function setUp(): void
    {
        $this->base = sys_get_temp_dir() . '/guardian-activation-' . bin2hex(random_bytes(6));
        $this->vendor = new RecordPackageFactory();
        $this->store = new SealedRecordStore(
            new TempWorkingDirectory($this->base),
            $this->vendor->sealedPackage(),
            new InMemoryLockFactory(),
        );
        $this->identity = new FixedIdentity('example.com');
        $this->hosts = new FixedHosts('example.com');
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
     * @return array{0: ActivationService, 1: EntitlementReader}
     */
    private function service(ScriptedExchange $exchange): array
    {
        $reader = new EntitlementReader($this->store, $this->identity, $this->hosts, $this->clock, null);

        return [
            new ActivationService($this->store, $exchange, $this->identity, $this->hosts, $reader, $this->clock, $this->logger),
            $reader,
        ];
    }

    /**
     * @param array<string, mixed> $overrides
     */
    private function confirmed(array $overrides = []): ProvisioningOutcome
    {
        $package = $this->vendor->package($overrides);
        $record = $this->vendor->sealedPackage()->open($package['payload'], $package['envelope'], self::NOW)->record;
        self::assertNotNull($record);

        return ProvisioningOutcome::confirmed($record, $package['bytes'], $package['envelope']);
    }

    /**
     * @param array<string, mixed> $overrides
     */
    private function seed(array $overrides = []): void
    {
        $package = $this->vendor->package($overrides);
        $this->store->replace($package['bytes'], $package['envelope'], self::NOW);
    }

    // ── First activation ────────────────────────────────────────────────────

    #[Test]
    public function aConfirmedActivationStoresTheCompletePackage(): void
    {
        $exchange = new ScriptedExchange($this->confirmed());
        [$service] = $this->service($exchange);

        $grant = $service->activate('GRD-TEST-0001-0002-0003');

        self::assertTrue($grant->isPro());
        self::assertSame('activate', $exchange->calls[0]['operation']);
        self::assertSame('example.com', $exchange->calls[0]['host']);
        self::assertSame(7, $this->store->read(self::NOW)->record->version);
    }

    #[Test]
    public function activationStoresTheDatesTheVendorIssuedAndNotTheLocalTime(): void
    {
        [$service] = $this->service(new ScriptedExchange($this->confirmed([
            'license_issued_at' => 1700000000,
            'license_starts_at' => 1700000000,
        ])));

        $service->activate('GRD-TEST-0001-0002-0003');

        $record = $this->store->read(self::NOW)->record;
        self::assertNotNull($record);
        self::assertSame(1700000000, $record->issuedAt);
        self::assertSame(1700000000, $record->startsAt);
        self::assertNotSame(self::NOW, $record->issuedAt);
    }

    #[Test]
    public function aLifetimeActivationIsStoredWithNoExpiry(): void
    {
        [$service] = $this->service(new ScriptedExchange($this->confirmed(['license_lifetime' => true])));

        $service->activate('GRD-TEST-0001-0002-0003');

        $record = $this->store->read(self::NOW)->record;
        self::assertNotNull($record);
        self::assertTrue($record->lifetime);
        self::assertNull($record->expiresAt);
    }

    #[Test]
    public function aRefusedFirstActivationLeavesTheInstallationUnlicensedAndStoresNothing(): void
    {
        [$service] = $this->service(new ScriptedExchange(ProvisioningOutcome::denied('nope')));

        $grant = $service->activate('WRONG-KEY');

        self::assertFalse($grant->isLicensed());
        self::assertFalse($this->store->read(self::NOW)->exists());
        self::assertFileDoesNotExist($this->base . '/license.json');
    }

    #[Test]
    public function activationIsNotAttemptedWithoutAnEstablishedHost(): void
    {
        $this->identity->set('', false);
        $exchange = new ScriptedExchange($this->confirmed());
        [$service] = $this->service($exchange);

        $service->activate('GRD-TEST-0001-0002-0003');

        self::assertSame([], $exchange->calls);
        self::assertFalse($this->store->read(self::NOW)->exists());
    }

    #[Test]
    public function anEmptyKeyWithNothingStoredIsNotSentAnywhere(): void
    {
        $exchange = new ScriptedExchange($this->confirmed());
        [$service] = $this->service($exchange);

        $grant = $service->activate('   ');

        self::assertSame([], $exchange->calls);
        self::assertFalse($grant->isLicensed());
    }

    // ── Update Licence ──────────────────────────────────────────────────────

    #[Test]
    public function pressingUpdateLicenceWithAnEmptyFieldRefreshesTheStoredKey(): void
    {
        $this->seed(['license_version' => 7]);
        $exchange = new ScriptedExchange($this->confirmed(['license_version' => 9]));
        [$service] = $this->service($exchange);

        $grant = $service->activate('');

        self::assertTrue($grant->isPro());
        self::assertSame('refresh', $exchange->calls[0]['operation']);
        // The refresh announces the version already held, as the protocol requires.
        self::assertSame(7, $exchange->calls[0]['version']);
        self::assertSame('GRD-TEST-0001-0002-0003', $exchange->calls[0]['key']);
        self::assertSame(9, $this->store->read(self::NOW)->record->version);
    }

    #[Test]
    public function reEnteringTheStoredKeyIsAlsoTreatedAsARefresh(): void
    {
        $this->seed();
        $exchange = new ScriptedExchange($this->confirmed(['license_version' => 9]));
        [$service] = $this->service($exchange);

        $service->activate('GRD-TEST-0001-0002-0003');

        self::assertSame('refresh', $exchange->calls[0]['operation']);
    }

    #[Test]
    public function aRenewalChangesTheExpiryAndVersionWithoutRewritingTheIssueHistory(): void
    {
        $this->seed(['license_version' => 7, 'license_issued_at' => 1700000000, 'license_starts_at' => 1700000000]);
        [$service] = $this->service(new ScriptedExchange($this->confirmed([
            'license_version' => 8,
            'license_issued_at' => 1700000000,
            'license_starts_at' => 1700000000,
            'license_expires_at' => 1900000000,
        ])));

        $service->refresh();

        $record = $this->store->read(self::NOW)->record;
        self::assertNotNull($record);
        self::assertSame(8, $record->version);
        self::assertSame(1900000000, $record->expiresAt);
        self::assertSame(1700000000, $record->issuedAt, 'the issue date was preserved');
        self::assertSame(1700000000, $record->startsAt, 'the start date was preserved');
    }

    #[Test]
    public function aRefreshCanMoveTheInstallationFromProToFreeAndBack(): void
    {
        $this->seed(['license_version' => 7, 'license_package' => 'pro']);
        [$toFree, $reader] = $this->service(new ScriptedExchange($this->confirmed([
            'license_version' => 8,
            'license_package' => 'free',
        ])));

        self::assertSame('free', $toFree->refresh()->tier->value);

        [$toPro] = $this->service(new ScriptedExchange($this->confirmed([
            'license_version' => 9,
            'license_package' => 'pro',
        ])));
        self::assertSame('pro', $toPro->refresh()->tier->value);
        unset($reader);
    }

    /**
     * A package outside this product's vocabulary is refused by the exchange
     * before it reaches this service; what is proven here is the consequence —
     * the working record survives, as it does for any other refused answer.
     */
    #[Test]
    public function aRefreshOfferingAnUnknownPackageLeavesTheStoredRecordInPlace(): void
    {
        $this->seed(['license_version' => 7, 'license_package' => 'pro']);
        $before = file_get_contents($this->base . '/license.json');

        [$service] = $this->service(new ScriptedExchange(
            ProvisioningOutcome::rejected('record_invalid_product', 'unusable package')
        ));
        $grant = $service->refresh();

        self::assertSame('pro', $grant->tier->value);
        self::assertSame(7, $this->store->read(self::NOW)->record->version);
        self::assertSame($before, file_get_contents($this->base . '/license.json'));
    }

    #[Test]
    public function aRefreshToANewerRecordOfTheSamePackageIsApplied(): void
    {
        $this->seed(['license_version' => 7]);
        [$service] = $this->service(new ScriptedExchange($this->confirmed(['license_version' => 9])));

        self::assertSame('pro', $service->refresh()->tier->value);
        self::assertSame(9, $this->store->read(self::NOW)->record->version);
    }

    #[Test]
    public function anUnreachableServiceLeavesTheStoredRecordExactlyAsItWas(): void
    {
        $this->seed(['license_version' => 7]);
        $before = file_get_contents($this->base . '/license.json');

        [$service] = $this->service(new ScriptedExchange(
            ProvisioningOutcome::unreachable('transport_failed', 'no route')
        ));
        $grant = $service->refresh();

        self::assertTrue($grant->isPro(), 'the working record still applies');
        self::assertSame($before, file_get_contents($this->base . '/license.json'));
    }

    #[Test]
    public function anAnswerThatFailsALocalCheckLeavesTheStoredRecordAlone(): void
    {
        $this->seed(['license_version' => 7]);

        [$service] = $this->service(new ScriptedExchange(
            ProvisioningOutcome::rejected('record_signature_invalid', 'bad')
        ));

        self::assertTrue($service->refresh()->isPro());
        self::assertSame(7, $this->store->read(self::NOW)->record->version);
    }

    #[Test]
    public function anOlderRecordCannotRollTheInstallationBack(): void
    {
        $this->seed(['license_version' => 9]);
        [$service] = $this->service(new ScriptedExchange($this->confirmed(['license_version' => 7])));

        $grant = $service->refresh();

        self::assertTrue($grant->isPro());
        self::assertSame(9, $this->store->read(self::NOW)->record->version, 'the newer record survived');
        self::assertSame('license_version_older', $grant->code);
    }

    #[Test]
    public function aRefreshThatConfirmsTheSameVersionIsAccepted(): void
    {
        $this->seed(['license_version' => 7, 'license_verified_at' => 1784000000]);
        [$service] = $this->service(new ScriptedExchange($this->confirmed([
            'license_version' => 7,
            'license_verified_at' => self::NOW,
        ])));

        $service->refresh();

        $record = $this->store->read(self::NOW)->record;
        self::assertNotNull($record);
        self::assertSame(7, $record->version);
        self::assertSame(self::NOW, $record->verifiedAt);
    }

    #[Test]
    public function aMistypedReplacementKeyNeverCostsTheWorkingRecord(): void
    {
        $this->seed(['license_version' => 7]);
        [$service] = $this->service(new ScriptedExchange(ProvisioningOutcome::denied('unknown key')));

        $grant = $service->activate('SOME-OTHER-KEY-TYPED-BY-MISTAKE');

        self::assertTrue($grant->isPro(), 'the existing record is untouched');
        self::assertSame(7, $this->store->read(self::NOW)->record->version);
        self::assertStringContainsString('existing licence was kept', $grant->message);
    }

    #[Test]
    public function anExplicitRefusalOfTheStoredKeyWithdrawsIt(): void
    {
        $this->seed();
        [$service] = $this->service(new ScriptedExchange(ProvisioningOutcome::denied('revoked')));

        $grant = $service->refresh();

        self::assertFalse($grant->isLicensed());
        self::assertFalse($this->store->read(self::NOW)->exists());
    }

    #[Test]
    public function refreshingWithNothingStoredDoesNothing(): void
    {
        $exchange = new ScriptedExchange($this->confirmed());
        [$service] = $this->service($exchange);

        $service->refresh();

        self::assertSame([], $exchange->calls);
    }

    #[Test]
    public function refreshingWithNoConfiguredDomainIsRefusedBeforeAnyRequestIsMade(): void
    {
        $this->seed(['license_domain' => 'example.com']);
        $this->hosts->set();
        $exchange = new ScriptedExchange($this->confirmed());
        [$service] = $this->service($exchange);

        $service->refresh();

        self::assertSame([], $exchange->calls);
        self::assertSame(7, $this->store->read(self::NOW)->record->version);
    }

    #[Test]
    public function aRefreshAsksAboutTheConfiguredHostEvenWhenTheStoredRecordDoesNotCoverIt(): void
    {
        // This is how a licence that has just gained a domain arrives: the stored
        // record cannot know about the new host, so refusing to ask would make the
        // new domain impossible to license.
        $this->seed(['license_domain' => 'example.com']);
        $this->hosts->set('new.example.com');
        $this->identity->set('new.example.com');
        $exchange = new ScriptedExchange($this->confirmed([
            'license_domain' => 'new.example.com',
            'license_domains' => ['example.com', 'new.example.com'],
            'license_version' => 8,
        ]));
        [$service] = $this->service($exchange);

        $service->refresh();

        self::assertSame('new.example.com', $exchange->calls[0]['host']);
    }

    #[Test]
    public function activationIsRefusedWhenNoDomainIsConfigured(): void
    {
        // An installation that names no host cannot be licensed for one, and
        // guessing at the backend's own hostname would be inventing an answer.
        $this->hosts->set();
        $exchange = new ScriptedExchange($this->confirmed());
        [$service, $reader] = $this->service($exchange);

        $grant = $service->activate('GRD-TEST-0001-0002-0003');

        self::assertSame([], $exchange->calls, 'nothing is asked before there is something to ask about');
        self::assertFalse($grant->isLicensed());
        self::assertSame('no_configured_domain', $grant->code);
        unset($reader);
    }

    #[Test]
    public function activationAsksAboutTheConfiguredSiteRatherThanTheBackendHostname(): void
    {
        // A backend reached at its own name verifies the site it belongs to. The
        // choice is the site configuration's, not the current URL's.
        $this->hosts->set('example.com', 'shop.example.com');
        $this->identity->set('backend.internal');
        $exchange = new ScriptedExchange($this->confirmed());
        [$service] = $this->service($exchange);

        $service->activate('GRD-TEST-0001-0002-0003');

        self::assertSame('example.com', $exchange->calls[0]['host']);
    }

    #[Test]
    public function activationUsesTheHostBeingServedWhenItIsOneOfTheConfiguredOnes(): void
    {
        $this->hosts->set('example.com', 'shop.example.com');
        $this->identity->set('shop.example.com');
        $exchange = new ScriptedExchange($this->confirmed(['license_domain' => 'shop.example.com']));
        [$service] = $this->service($exchange);

        $service->activate('GRD-TEST-0001-0002-0003');

        self::assertSame('shop.example.com', $exchange->calls[0]['host']);
    }

    #[Test]
    public function withdrawingRemovesTheRecord(): void
    {
        $this->seed();
        [$service] = $this->service(new ScriptedExchange());

        $grant = $service->withdraw();

        self::assertFalse($grant->isLicensed());
        self::assertFalse($this->store->read(self::NOW)->exists());
    }
}
