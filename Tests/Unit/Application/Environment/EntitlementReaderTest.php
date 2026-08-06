<?php

declare(strict_types=1);

/*
 * This file is part of the "Guardian for TYPO3" extension for TYPO3 CMS.
 *
 * @author    V&T Innovations Team
 * @license   LGPL-3.0-or-later
 * @copyright V&T Innovations 2026 - 2028
 */

namespace Vtinnovations\GuardianTypo3\Tests\Unit\Application\Environment;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Vtinnovations\GuardianTypo3\Application\Environment\CapabilityAssertion;
use Vtinnovations\GuardianTypo3\Application\Environment\EntitlementReader;
use Vtinnovations\GuardianTypo3\Domain\Environment\CapabilityTier;
use Vtinnovations\GuardianTypo3\Domain\Exception\GuardianException;
use Vtinnovations\GuardianTypo3\Infrastructure\Configuration\SealedRecordStore;
use Vtinnovations\GuardianTypo3\Infrastructure\Registry\ServiceEndpoint;
use Vtinnovations\GuardianTypo3\Infrastructure\Registry\UsagePing;
use Vtinnovations\GuardianTypo3\Tests\Unit\Support\FixedClock;
use Vtinnovations\GuardianTypo3\Tests\Unit\Support\FixedHosts;
use Vtinnovations\GuardianTypo3\Tests\Unit\Support\FixedIdentity;
use Vtinnovations\GuardianTypo3\Tests\Unit\Support\InMemoryLockFactory;
use Vtinnovations\GuardianTypo3\Tests\Unit\Support\RecordPackageFactory;
use Vtinnovations\GuardianTypo3\Tests\Unit\Support\RecordingPingTransport;
use Vtinnovations\GuardianTypo3\Tests\Unit\Support\TempWorkingDirectory;

/**
 * How the stored record, the installation's host and the clock combine into an
 * entitlement — and, above all, when they do not.
 */
final class EntitlementReaderTest extends TestCase
{
    private const NOW = 1784880600;

    private string $base;
    private RecordPackageFactory $vendor;
    private SealedRecordStore $store;
    private FixedIdentity $identity;
    private FixedHosts $hosts;
    private FixedClock $clock;
    private RecordingPingTransport $transport;

    protected function setUp(): void
    {
        UsagePing::resetForTesting();
        $this->base = sys_get_temp_dir() . '/guardian-reader-' . bin2hex(random_bytes(6));
        $this->vendor = new RecordPackageFactory();
        $this->store = new SealedRecordStore(
            new TempWorkingDirectory($this->base),
            $this->vendor->sealedPackage(),
            new InMemoryLockFactory(),
        );
        $this->identity = new FixedIdentity('example.com');
        $this->hosts = new FixedHosts('example.com');
        $this->clock = new FixedClock(self::NOW);
        $this->transport = new RecordingPingTransport();
    }

    protected function tearDown(): void
    {
        UsagePing::resetForTesting();
        if (is_dir($this->base)) {
            exec('rm -rf ' . escapeshellarg($this->base));
        }
    }

    /**
     * @param array<string, mixed> $overrides
     */
    private function storeRecord(array $overrides = []): void
    {
        $package = $this->vendor->package($overrides);
        $this->store->replace($package['bytes'], $package['envelope'], self::NOW);
    }

    private function reader(bool $withPing = false): EntitlementReader
    {
        return new EntitlementReader(
            $this->store,
            $this->identity,
            $this->hosts,
            $this->clock,
            $withPing ? new UsagePing($this->transport, new ServiceEndpoint(), true) : null,
        );
    }

    #[Test]
    public function withNothingStoredNothingIsGranted(): void
    {
        $grant = $this->reader()->grant();

        self::assertSame('none', $grant->state);
        self::assertFalse($grant->isLicensed());
        self::assertFalse($grant->isPro());
        self::assertSame(CapabilityTier::None, $grant->tier);
    }

    #[Test]
    public function aValidProRecordOnItsOwnHostGrantsPro(): void
    {
        $this->storeRecord();

        $grant = $this->reader()->grant();

        self::assertSame('pro', $grant->state);
        self::assertTrue($grant->isPro());
        self::assertTrue($grant->allows(CapabilityTier::Free));
        self::assertTrue($grant->allows(CapabilityTier::Pro));
    }

    #[Test]
    public function aFreeRecordGrantsFreeButNotPro(): void
    {
        $this->storeRecord(['license_package' => 'free']);

        $grant = $this->reader()->grant();

        self::assertSame('free', $grant->state);
        self::assertTrue($grant->isLicensed());
        self::assertFalse($grant->isPro());
        self::assertTrue($grant->allows(CapabilityTier::Free));
        self::assertFalse($grant->allows(CapabilityTier::Pro));
    }

    /**
     * The product is sold as "free" or "pro". Anything else belongs to another
     * product, and what it would unlock here is not this side's to guess.
     *
     * @return list<array{0: string}>
     */
    public static function packagesThisProductDoesNotSell(): array
    {
        return [['trial'], ['starter'], ['basic'], ['enterprise']];
    }

    /**
     * A signed document naming an unknown package is not read as the smallest
     * tier — it cannot even be stored, so there is no state in which the
     * interface could show it as partially licensed.
     */
    #[Test]
    #[DataProvider('packagesThisProductDoesNotSell')]
    public function aRecordForAnotherPackageCannotBeStoredAtAll(string $package): void
    {
        $this->expectException(GuardianException::class);
        $this->storeRecord(['license_package' => $package]);
    }

    #[Test]
    public function aRecordForAnotherPackageWrittenDirectlyGrantsNothing(): void
    {
        // Bypassing the store the way a hand-edited or migrated state would: the
        // pair on disk is a properly signed vendor package, it just names a
        // package this product does not have.
        $package = $this->vendor->package(['license_package' => 'enterprise']);
        if (!is_dir($this->base)) {
            mkdir($this->base, 0o770, true);
        }
        file_put_contents($this->base . '/license.json', $package['bytes']);
        file_put_contents(
            $this->base . '/license.seal.json',
            (string) json_encode($package['envelope'], \JSON_UNESCAPED_SLASHES | \JSON_UNESCAPED_UNICODE)
        );

        $grant = $this->reader()->grant();

        self::assertFalse($grant->isLicensed());
        self::assertSame(CapabilityTier::None, $grant->tier);
    }

    /**
     * @return list<array{0: string, 1: string}>
     */
    public static function foreignHosts(): array
    {
        return [
            ['example.com', 'www.example.com'],
            ['www.example.com', 'example.com'],
            ['example.com', 'shop.example.com'],
            ['shop.example.com', 'example.com'],
            ['shop.example.com', 'blog.example.com'],
            ['shop.example.com', 'admin.shop.example.com'],
            ['example.com', 'malicious-example.com'],
        ];
    }

    #[Test]
    #[DataProvider('foreignHosts')]
    public function aRecordIsWorthlessOnAnyHostButItsOwn(string $licensed, string $running): void
    {
        $this->storeRecord(['license_domain' => $licensed, 'license_domains' => [$licensed]]);
        // The installation is configured as, and is being served as, a name the
        // vendor never authorised.
        $this->hosts->set($running);
        $this->identity->set($running);

        $grant = $this->reader()->grant();

        self::assertSame('domain_mismatch', $grant->state);
        self::assertFalse($grant->isLicensed());
        self::assertSame(CapabilityTier::None, $grant->tier);
        self::assertSame('', $grant->matchedDomain);
    }

    #[Test]
    public function copyingTheStoredPairToAnotherHostDoesNotCarryTheEntitlement(): void
    {
        // The pair is intact and cryptographically sound; only the installation
        // it was copied to is configured for a different name.
        $this->storeRecord(['license_domain' => 'example.com']);
        self::assertTrue($this->reader()->grant()->isPro());

        $this->hosts->set('staging.example.com');
        $this->identity->set('staging.example.com');
        self::assertFalse($this->reader()->grant()->isLicensed());
    }

    #[Test]
    public function anyOneOfSeveralConfiguredDomainsIsEnough(): void
    {
        // Two sites on one installation, one of them licensed. A single exact
        // member of both sets authorises the installation.
        $this->storeRecord(['license_domain' => 'shop.example.com', 'license_domains' => ['shop.example.com']]);
        $this->hosts->set('blog.example.com', 'shop.example.com');
        $this->identity->set('blog.example.com');

        $grant = $this->reader()->grant();

        self::assertTrue($grant->isPro());
        self::assertSame('shop.example.com', $grant->matchedDomain);
    }

    #[Test]
    public function theHostBeingServedIsPreferredWhenItQualifies(): void
    {
        $this->storeRecord([
            'license_domain' => 'a.example.com',
            'license_domains' => ['a.example.com', 'b.example.com'],
        ]);
        $this->hosts->set('a.example.com', 'b.example.com');
        $this->identity->set('b.example.com');

        self::assertSame('b.example.com', $this->reader()->grant()->matchedDomain);
    }

    #[Test]
    public function aLargeAllowanceIsNotAWildcard(): void
    {
        // 9999 is what the vendor reports for an instance-bound product. It says
        // nothing about hosts it did not list.
        $this->storeRecord([
            'license_domain' => 'example.com',
            'license_domains' => ['example.com'],
            'license_max_domains' => 9999,
        ]);
        $this->hosts->set('other.example.com');
        $this->identity->set('other.example.com');

        self::assertFalse($this->reader()->grant()->isLicensed());
    }

    #[Test]
    public function aBoundSetLargerThanTheAllowanceStaysValid(): void
    {
        // The vendor lowers an allowance without unbinding what is already bound;
        // taking the installation dark for that would be this side's invention.
        $this->storeRecord([
            'license_domain' => 'a.example.com',
            'license_domains' => ['a.example.com', 'b.example.com', 'c.example.com'],
            'license_max_domains' => 1,
        ]);
        $this->hosts->set('a.example.com');

        self::assertTrue($this->reader()->grant()->isPro());
    }

    #[Test]
    public function aRecordFromBeforeTheDomainSetAuthorisesNothingUntilRefreshed(): void
    {
        $this->storeRecord(['license_domains' => null, 'license_max_domains' => null]);

        $grant = $this->reader()->grant();

        self::assertFalse($grant->isLicensed());
        self::assertSame('refresh_required', $grant->state);
        // The record is kept, so the key it holds can still fetch a current one.
        self::assertNotNull($grant->record);
    }

    #[Test]
    public function anInstallationWithNoConfiguredDomainIsNotEntitled(): void
    {
        $this->storeRecord();
        $this->hosts->set();

        $grant = $this->reader()->grant();

        self::assertSame('no_configured_domain', $grant->state);
        self::assertFalse($grant->isLicensed());
    }

    #[Test]
    public function representationDifferencesInTheHostStillMatch(): void
    {
        $this->storeRecord(['license_domain' => 'example.com']);

        foreach (['EXAMPLE.COM', 'example.com.', 'example.com:8443'] as $variant) {
            $this->hosts->set($variant);
            $this->identity->set($variant);
            self::assertTrue($this->reader()->grant()->isPro(), $variant);
        }
    }

    #[Test]
    public function aWorkerIsHeldToTheConfiguredHostsRatherThanSkippingTheCheck(): void
    {
        $this->storeRecord(['license_domain' => 'example.com']);
        // No live request: a console run, the scheduler or a queue worker. The
        // configured inventory still answers, so the check is made rather than
        // waived — and it fails when the configuration does not match.
        $this->identity->set('', false);
        self::assertTrue($this->reader()->grant()->isPro());

        $this->hosts->set('elsewhere.example.com');
        self::assertFalse($this->reader()->grant()->isLicensed());
    }

    #[Test]
    public function anExpiredProRecordDropsToTheAuthorisedFreeFallback(): void
    {
        $this->storeRecord(['free_available' => true]);
        $this->clock->set(1815536001);

        $grant = $this->reader()->grant();

        self::assertSame('free_fallback', $grant->state);
        self::assertTrue($grant->isLicensed());
        self::assertFalse($grant->isPro());
        // The fallback is the same record: same key, same authorised hosts.
        self::assertSame('example.com', $grant->matchedDomain);
        self::assertNotNull($grant->record);
    }

    #[Test]
    public function anExpiredRecordWithoutAnAuthorisedFallbackGrantsNothing(): void
    {
        $this->storeRecord(['free_available' => false]);
        $this->clock->set(1815536001);

        $grant = $this->reader()->grant();

        self::assertSame('expired', $grant->state);
        self::assertFalse($grant->isLicensed());
        self::assertSame(CapabilityTier::None, $grant->tier);
    }

    #[Test]
    public function anExpiredFreeRecordHasNoLesserTierToFallBackTo(): void
    {
        // The flag says a Free continuation is authorised, but the record is
        // already Free: there is nothing below it, so it simply ends.
        $this->storeRecord(['license_package' => 'free', 'free_available' => true]);
        $this->clock->set(1815536001);

        $grant = $this->reader()->grant();

        self::assertSame('expired', $grant->state);
        self::assertFalse($grant->isLicensed());
    }

    #[Test]
    public function theFallbackKeepsManualBackupButNothingElse(): void
    {
        $this->storeRecord(['free_available' => true]);
        $this->clock->set(1815536001);
        $assertion = new CapabilityAssertion($this->reader());

        $assertion->requireLicensed('Creating a backup'); // does not throw

        $this->expectException(GuardianException::class);
        $this->expectExceptionMessage('Running an update job requires a valid Pro licence');
        $assertion->requirePro('Running an update job');
    }

    #[Test]
    public function anExpiredRecordWithoutAFallbackRefusesEvenManualBackup(): void
    {
        $this->storeRecord(['free_available' => false]);
        $this->clock->set(1815536001);
        $assertion = new CapabilityAssertion($this->reader());

        $this->expectException(GuardianException::class);
        $this->expectExceptionMessage('Creating a backup requires a valid Free or Pro licence');
        $assertion->requireLicensed('Creating a backup');
    }

    #[Test]
    public function aRecordThatHasNotStartedYetGrantsNothing(): void
    {
        $this->storeRecord(['license_starts_at' => self::NOW + 86400, 'license_expires_at' => self::NOW + 200000]);

        $grant = $this->reader()->grant();

        self::assertSame('not_started', $grant->state);
        self::assertFalse($grant->isLicensed());
    }

    #[Test]
    public function aTamperedStoreGrantsNothing(): void
    {
        $this->storeRecord();
        file_put_contents($this->base . '/license.json', '{"license_package":"pro"}');

        $grant = $this->reader()->grant();

        self::assertSame('invalid', $grant->state);
        self::assertFalse($grant->isLicensed());
    }

    #[Test]
    public function aStaleConfirmationIsReportedWithoutWithdrawingTheEntitlement(): void
    {
        $this->storeRecord(['license_verified_at' => self::NOW - 200000]);

        $grant = $this->reader()->grant();

        self::assertTrue($grant->isPro());
        self::assertTrue($grant->confirmationStale);
    }

    #[Test]
    public function theInvocationSignalCarriesExactlyTheProjectAndTheHost(): void
    {
        $this->storeRecord();

        $this->reader(true)->grant();

        self::assertCount(1, $this->transport->sent);
        self::assertSame(
            ['project' => 'Guardian', 'domain' => 'example.com'],
            json_decode($this->transport->sent[0]['body'], true)
        );
        self::assertSame('https://www.v-t.one/rest/api/v1/log-envoke', $this->transport->sent[0]['url']);
    }

    #[Test]
    public function theInvocationSignalFiresAtMostOncePerInvocation(): void
    {
        $this->storeRecord();
        $reader = $this->reader(true);

        $reader->grant();
        $reader->forget();
        $reader->grant();
        $reader->forget();
        $reader->grant();

        self::assertCount(1, $this->transport->sent);
    }

    #[Test]
    public function theAssertionPassesEveryOperationForAValidProRecord(): void
    {
        $this->storeRecord();
        $assertion = new CapabilityAssertion($this->reader());

        $assertion->requireLicensed('Creating a backup');
        $assertion->requirePro('Running an update job');

        self::assertTrue($this->reader()->isPro());
    }

    #[Test]
    public function aFreeRecordCoversBackupAndNothingBeyondIt(): void
    {
        $this->storeRecord(['license_package' => 'free']);
        $assertion = new CapabilityAssertion($this->reader());

        $assertion->requireLicensed('Creating a backup'); // does not throw

        $this->expectException(GuardianException::class);
        $this->expectExceptionMessage('Running an update job requires a valid Pro licence');
        $assertion->requirePro('Running an update job');
    }

    #[Test]
    public function theAssertionRefusesEveryOperationWithoutAValidRecord(): void
    {
        $assertion = new CapabilityAssertion($this->reader());

        $this->expectException(GuardianException::class);
        $this->expectExceptionMessage('Running an update job requires a valid Pro licence');
        $assertion->requirePro('Running an update job');
    }

    #[Test]
    public function theAssertionRefusesEverythingWhenNothingIsStored(): void
    {
        $assertion = new CapabilityAssertion($this->reader());

        $this->expectException(GuardianException::class);
        $assertion->requireLicensed('Creating a backup');
    }

    #[Test]
    public function thePublicProjectionNeverExposesTheKeyOrTheSignature(): void
    {
        $this->storeRecord(['license_key' => 'GRD-SECRET-KEY-VALUE-0001']);

        $public = $this->reader()->grant()->toPublicArray();
        $encoded = json_encode($public);

        self::assertIsString($encoded);
        self::assertStringNotContainsString('GRD-SECRET-KEY-VALUE-0001', $encoded);
        self::assertStringNotContainsString('SECRET', $encoded);
        self::assertArrayNotHasKey('signature', $public);
        self::assertArrayNotHasKey('license_key', $public);
        self::assertArrayNotHasKey('license_md5', $public);
        self::assertTrue($public['signature_present']);
    }
}
