<?php

declare(strict_types=1);

/*
 * This file is part of the "Guardian for TYPO3" extension for TYPO3 CMS.
 *
 * @author    V&T Innovations Team
 * @license   LGPL-3.0-or-later
 * @copyright V&T Innovations 2026 - 2028
 */

namespace Vtinnovations\GuardianTypo3\Tests\Unit\Application\License;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Vtinnovations\GuardianTypo3\Application\Contract\LicenseStateRepositoryInterface;
use Vtinnovations\GuardianTypo3\Application\Contract\LicenseUpdaterInterface;
use Vtinnovations\GuardianTypo3\Application\Contract\LicenseVerifierInterface;
use Vtinnovations\GuardianTypo3\Application\License\LicenseGuard;
use Vtinnovations\GuardianTypo3\Application\License\LicenseManager;
use Vtinnovations\GuardianTypo3\Domain\Clock\ClockInterface;
use Vtinnovations\GuardianTypo3\Domain\License\LicenseState;
use Vtinnovations\GuardianTypo3\Domain\License\LicenseValidationStatus;
use Vtinnovations\GuardianTypo3\Domain\License\LicenseVerificationResult;

final class LicenseManagerTest extends TestCase
{
    private \DateTimeImmutable $now;
    private InMemoryLicenseRepository $repository;
    private StubLicenseVerifier $verifier;
    private LicenseManager $manager;

    protected function setUp(): void
    {
        $this->now = new \DateTimeImmutable('2026-05-04T12:00:00+00:00');
        $this->repository = new InMemoryLicenseRepository();
        $this->verifier = new StubLicenseVerifier();
        $clock = new class($this->now) implements ClockInterface {
            public function __construct(private readonly \DateTimeImmutable $now) {}
            public function now(): \DateTimeImmutable { return $this->now; }
        };
        $this->manager = new LicenseManager($this->repository, $this->verifier, $clock);
    }

    #[Test]
    public function successfulProActivationBindsAndPersistsTheDomain(): void
    {
        $this->verifier->next = LicenseVerificationResult::valid(null, ' PRO ', 'Activated');
        $status = $this->manager->activate('TEST-KEY-NOT-REAL', 'WWW.Example.COM');

        self::assertTrue($status->licensed);
        self::assertTrue($status->pro);
        self::assertSame('www.example.com', $this->repository->state->domain);
        self::assertSame('pro', $this->repository->state->package);
        self::assertSame($this->now->getTimestamp(), $this->repository->state->verifiedAt);
    }

    #[Test]
    public function nonProPackageIsAFreeLicense(): void
    {
        $this->verifier->next = LicenseVerificationResult::valid(null, 'starter');
        $status = $this->manager->activate('TEST-FREE-NOT-REAL', 'example.com');

        self::assertTrue($status->licensed);
        self::assertFalse($status->pro);
        self::assertSame('free', $status->status);
    }

    #[Test]
    public function failedFirstActivationKeepsOnlyTheRejectedKey(): void
    {
        $this->verifier->next = LicenseVerificationResult::denied('Rejected');
        $status = $this->manager->activate('REJECTED-NOT-REAL', 'example.com');

        self::assertSame('invalid', $status->status);
        self::assertSame('REJECTED-NOT-REAL', $this->repository->state->key);
        self::assertSame(0, $this->repository->state->verifiedAt);
        self::assertNull($this->repository->state->expiresAt);
        self::assertSame('', $this->repository->state->domain);
        self::assertSame('', $this->repository->state->package);
    }

    #[Test]
    public function publicStatusMasksTheKeyAndRedactsItFromServerMessages(): void
    {
        $key = 'VISIBLE-NOWHERE-NOT-REAL';
        $this->verifier->next = LicenseVerificationResult::denied('Rejected ' . $key);
        $public = $this->manager->activate($key, 'example.com')->toPublicArray();

        self::assertNotSame($key, $public['key_preview']);
        self::assertStringNotContainsString($key, (string) $public['message']);
        self::assertArrayNotHasKey('license_key', $public);
    }

    #[Test]
    public function unreachableFirstActivationNeverReceivesGrace(): void
    {
        $this->verifier->next = LicenseVerificationResult::unreachable('Timeout');
        $status = $this->manager->activate('OFFLINE-NOT-REAL', 'example.com');

        self::assertSame('unreachable', $status->status);
        self::assertFalse($status->licensed);
        self::assertSame(0, $this->repository->state->verifiedAt);
    }

    #[Test]
    public function transientRefreshPreservesAValidCache(): void
    {
        $this->repository->state = new LicenseState('CACHED-NOT-REAL', $this->now->getTimestamp() - 3600, null, 'bound.example', 'pro', LicenseValidationStatus::Valid);
        $this->verifier->next = LicenseVerificationResult::unreachable('Timeout');
        $status = $this->manager->refresh('other.example');

        self::assertSame('unreachable', $status->status);
        self::assertTrue($status->licensed);
        self::assertTrue($status->pro);
        self::assertSame('bound.example', $this->verifier->domain);
    }

    #[Test]
    public function explicitDenialRevokesAValidCacheImmediately(): void
    {
        $this->repository->state = new LicenseState('CACHED-NOT-REAL', $this->now->getTimestamp() - 3600, null, 'example.com', 'pro', LicenseValidationStatus::Valid);
        $this->verifier->next = LicenseVerificationResult::denied('Revoked');
        $status = $this->manager->refresh('example.com');

        self::assertFalse($status->licensed);
        self::assertSame(0, $this->repository->state->verifiedAt);
    }

    #[Test]
    public function expiredLicenseIsReportedAsExpired(): void
    {
        $this->repository->state = new LicenseState('EXPIRED-NOT-REAL', $this->now->getTimestamp() - 3600, $this->now->getTimestamp() - 1, 'example.com', 'pro', LicenseValidationStatus::Valid);
        self::assertSame('expired', $this->manager->currentStatus()->status);
        self::assertFalse($this->manager->currentStatus()->licensed);
    }

    #[Test]
    public function staleCacheIsFlaggedButOldVerificationStaysLicensedFromStoredDates(): void
    {
        // The cache-age flag is informational; validity now derives from the
        // stored issue/expiry dates, so no periodic remote re-check is forced.
        $this->repository->state = new LicenseState('STALE-NOT-REAL', $this->now->getTimestamp() - 90000, null, 'example.com', 'free', LicenseValidationStatus::Valid);
        self::assertTrue($this->manager->currentStatus()->licensed);
        self::assertTrue($this->manager->currentStatus()->cacheStale);

        // Verified long ago, no expiry → still licensed offline.
        $this->repository->state = new LicenseState('OLD-NOT-REAL', $this->now->getTimestamp() - 604801, null, 'example.com', 'pro', LicenseValidationStatus::Valid);
        self::assertTrue($this->manager->currentStatus()->licensed);

        // But an expiry in the past is authoritative.
        $this->repository->state = new LicenseState('EXP-NOT-REAL', $this->now->getTimestamp() - 604801, $this->now->getTimestamp() - 1, 'example.com', 'pro', LicenseValidationStatus::Valid);
        self::assertFalse($this->manager->currentStatus()->licensed);
    }

    #[Test]
    public function clearingRemovesStoredState(): void
    {
        $this->repository->state = new LicenseState('REMOVE-NOT-REAL', 1, null, 'example.com', 'pro', LicenseValidationStatus::Valid);
        self::assertSame('none', $this->manager->clear()->status);
        self::assertTrue($this->repository->cleared);
    }

    #[Test]
    public function activationAndUpdateCallTheRemoteVerifierExactlyOnce(): void
    {
        $this->verifier->next = LicenseVerificationResult::valid(null, 'pro', 'ok');
        $this->manager->activate('KEY-A-NOT-REAL', 'example.com');
        self::assertSame(1, $this->verifier->calls, 'activation performs one remote verification');

        // An explicit update/replacement verifies again.
        $this->manager->activate('KEY-B-NOT-REAL', 'example.com');
        self::assertSame(2, $this->verifier->calls);
    }

    #[Test]
    public function explicitRefreshCallsTheRemoteVerifier(): void
    {
        $this->repository->state = new LicenseState('CACHED-NOT-REAL', $this->now->getTimestamp() - 3600, null, 'example.com', 'pro', LicenseValidationStatus::Valid);
        $this->verifier->next = LicenseVerificationResult::valid(null, 'pro', 'ok');
        $this->manager->refresh('example.com');
        self::assertSame(1, $this->verifier->calls);
    }

    #[Test]
    public function normalStatusAndEntitlementChecksNeverCallTheRemoteVerifier(): void
    {
        $this->repository->state = new LicenseState('CACHED-NOT-REAL', $this->now->getTimestamp() - 3600, null, 'example.com', 'pro', LicenseValidationStatus::Valid);

        $status = $this->manager->currentStatus();
        $this->manager->currentStatus();      // repeated status endpoint loads
        (new LicenseGuard($this->manager))->isPro();   // an entitlement check
        (new LicenseGuard($this->manager))->isLicensed();

        self::assertTrue($status->pro);
        self::assertSame(0, $this->verifier->calls, 'local checks make no remote call');
    }

    #[Test]
    public function successfulUpdateReplacesTheStoredLicense(): void
    {
        $this->repository->state = new LicenseState('OLD-FREE-NOT-REAL', $this->now->getTimestamp() - 3600, null, 'example.com', 'free', LicenseValidationStatus::Valid);
        $this->verifier->next = LicenseVerificationResult::valid($this->now->getTimestamp() + 86400, 'pro', 'ok', $this->now->getTimestamp() - 10);

        $status = $this->manager->activate('NEW-PRO-NOT-REAL', 'example.com');

        self::assertSame('NEW-PRO-NOT-REAL', $this->repository->state->key);
        self::assertTrue($status->pro);
        self::assertSame('pro', $this->repository->state->package);
    }

    #[Test]
    public function failedUpdatePreservesThePreviousValidLicense(): void
    {
        $previous = new LicenseState('GOOD-PRO-NOT-REAL', $this->now->getTimestamp() - 3600, null, 'example.com', 'pro', LicenseValidationStatus::Valid);
        $this->repository->state = $previous;
        $this->verifier->next = LicenseVerificationResult::denied('Bad key');

        $status = $this->manager->activate('WRONG-KEY-NOT-REAL', 'example.com');

        self::assertSame('GOOD-PRO-NOT-REAL', $this->repository->state->key, 'the old license is kept');
        self::assertTrue($this->repository->state->isLicensed($this->now));
        self::assertTrue($status->licensed);
        self::assertTrue($status->pro);
    }

    #[Test]
    public function startEndAndPackageArePersistedFromVerificationAndEnforcedLocally(): void
    {
        $start = $this->now->getTimestamp() - 5 * 86400;
        $end = $this->now->getTimestamp() + 30 * 86400;
        $this->verifier->next = LicenseVerificationResult::valid($end, 'pro', 'ok', $start);

        $this->manager->activate('DATED-NOT-REAL', 'example.com');

        self::assertSame($start, $this->repository->state->issuedAt);
        self::assertSame($end, $this->repository->state->expiresAt);
        self::assertSame('pro', $this->repository->state->package);
        // Enforced locally with no further remote call.
        $this->verifier->calls = 0;
        self::assertTrue($this->manager->currentStatus()->pro);
        self::assertSame(0, $this->verifier->calls);
    }

    #[Test]
    public function freePackageIsPersistedAndDoesNotUnlockPro(): void
    {
        $this->verifier->next = LicenseVerificationResult::valid(null, 'free', 'ok');
        $status = $this->manager->activate('FREE-NOT-REAL', 'example.com');

        self::assertTrue($status->licensed);
        self::assertFalse($status->pro);
        self::assertSame('free', $this->repository->state->package);
    }

    #[Test]
    public function updateWithAnEmptyFieldReusesTheStoredKeyServerSide(): void
    {
        $this->repository->state = new LicenseState('STORED-SECRET-NOT-REAL', $this->now->getTimestamp() - 3600, null, 'example.com', 'pro', LicenseValidationStatus::Valid);
        $this->verifier->next = LicenseVerificationResult::valid(null, 'pro', 'ok');

        $status = $this->manager->activate('', 'example.com');

        self::assertSame('STORED-SECRET-NOT-REAL', $this->verifier->lastKey, 'the stored key is reused server-side');
        self::assertSame(1, $this->verifier->calls);
        self::assertTrue($status->licensed);
    }

    #[Test]
    public function updateWithANewKeyVerifiesThatKeyAndReplacesTheLicense(): void
    {
        $this->repository->state = new LicenseState('OLD-KEY-NOT-REAL', $this->now->getTimestamp() - 3600, null, 'example.com', 'free', LicenseValidationStatus::Valid);
        $this->verifier->next = LicenseVerificationResult::valid(null, 'pro', 'ok');

        $this->manager->activate('NEW-KEY-NOT-REAL', 'example.com');

        self::assertSame('NEW-KEY-NOT-REAL', $this->verifier->lastKey);
        self::assertSame('NEW-KEY-NOT-REAL', $this->repository->state->key);
        self::assertSame('pro', $this->repository->state->package);
    }

    #[Test]
    public function freeToProUpdateRefreshesPackageAndGating(): void
    {
        $this->repository->state = new LicenseState('KEY-NOT-REAL', $this->now->getTimestamp() - 3600, null, 'example.com', 'free', LicenseValidationStatus::Valid);
        $this->verifier->next = LicenseVerificationResult::valid(null, 'pro', 'ok', null, ['scheduled_backups', 'recovery']);

        $status = $this->manager->activate('', 'example.com');

        self::assertTrue($status->pro);
        self::assertSame('pro', $this->repository->state->package);
        self::assertSame(['scheduled_backups', 'recovery'], $this->repository->state->features);
        self::assertContains('recovery', $status->toPublicArray()['features']);
    }

    #[Test]
    public function proToFreeUpdateDowngradesButStaysLicensed(): void
    {
        $this->repository->state = new LicenseState('KEY-NOT-REAL', $this->now->getTimestamp() - 3600, null, 'example.com', 'pro', LicenseValidationStatus::Valid);
        $this->verifier->next = LicenseVerificationResult::valid(null, 'free', 'ok');

        $status = $this->manager->activate('', 'example.com');

        self::assertTrue($status->licensed);
        self::assertFalse($status->pro);
        self::assertSame('free', $this->repository->state->package);
    }

    #[Test]
    public function expiryRenewalIsPersisted(): void
    {
        $oldExpiry = $this->now->getTimestamp() + 86400;
        $newExpiry = $this->now->getTimestamp() + 365 * 86400;
        $this->repository->state = new LicenseState('KEY-NOT-REAL', $this->now->getTimestamp() - 3600, $oldExpiry, 'example.com', 'pro', LicenseValidationStatus::Valid);
        $this->verifier->next = LicenseVerificationResult::valid($newExpiry, 'pro', 'ok', $this->now->getTimestamp());

        $this->manager->activate('', 'example.com');

        self::assertSame($newExpiry, $this->repository->state->expiresAt);
        self::assertSame($this->now->getTimestamp(), $this->repository->state->issuedAt);
    }

    #[Test]
    public function failedEmptyFieldUpdatePreservesTheActiveLicense(): void
    {
        $previous = new LicenseState('GOOD-KEY-NOT-REAL', $this->now->getTimestamp() - 3600, $this->now->getTimestamp() + 86400, 'example.com', 'pro', LicenseValidationStatus::Valid, $this->now->getTimestamp() - 100, ['recovery']);
        $this->repository->state = $previous;
        $this->verifier->next = LicenseVerificationResult::unreachable('Timeout');

        $status = $this->manager->activate('', 'example.com');

        self::assertSame($previous->toArray(), $this->repository->state->toArray(), 'stored data is unchanged on failure');
        self::assertTrue($status->licensed);
        self::assertTrue($status->pro);
    }

    #[Test]
    public function verificationTimeIsNeverStoredAsTheIssueOrStartDate(): void
    {
        // The historical bug: license_issued_at was copied from the verification
        // time. Now the server-supplied dates are stored verbatim and stay
        // distinct from license_verified_at (= now).
        $issued = $this->now->getTimestamp() - 40 * 86400;
        $starts = $this->now->getTimestamp() - 35 * 86400;
        $expires = $this->now->getTimestamp() + 325 * 86400;
        $this->verifier->next = LicenseVerificationResult::valid($expires, 'pro', 'ok', $issued, [], $starts);

        $this->manager->activate('DATED-NOT-REAL', 'example.com');

        self::assertSame($issued, $this->repository->state->issuedAt);
        self::assertSame($starts, $this->repository->state->startsAt);
        self::assertSame($expires, $this->repository->state->expiresAt);
        self::assertSame($this->now->getTimestamp(), $this->repository->state->verifiedAt);
        self::assertNotSame($this->repository->state->verifiedAt, $this->repository->state->issuedAt);
    }

    #[Test]
    public function anEmptyServerStartDateIsNotFabricatedFromTheVerificationTime(): void
    {
        // Server returns validity but no issue/start date → they stay unset (0/null),
        // never the local verification time.
        $this->verifier->next = LicenseVerificationResult::valid(null, 'pro', 'ok');
        $this->manager->activate('NO-DATES-NOT-REAL', 'example.com');

        self::assertNull($this->repository->state->issuedAt);
        self::assertNull($this->repository->state->startsAt);
        self::assertSame(0, $this->repository->state->toArray()['license_issued_at']);
        self::assertSame($this->now->getTimestamp(), $this->repository->state->verifiedAt);
    }

    #[Test]
    public function updateLicenseUsesTheUpdaterEndpointAndStoresTheFullSignedDocument(): void
    {
        $updater = new StubLicenseUpdater();
        $updater->next = LicenseVerificationResult::valid(
            null,
            'pro',
            'updated',
            $this->now->getTimestamp() - 5 * 86400,
            ['recovery', 'scheduled_backups'],
            $this->now->getTimestamp() - 4 * 86400,
            true,          // lifetime
            9,             // license_version
            'DOC-SIG=='    // signature
        );
        $clock = new class($this->now) implements ClockInterface {
            public function __construct(private readonly \DateTimeImmutable $now) {}
            public function now(): \DateTimeImmutable { return $this->now; }
        };
        $manager = new LicenseManager($this->repository, $this->verifier, $clock, null, null, null, null, null, $updater);

        $status = $manager->activate('UPD-KEY-NOT-REAL', 'example.com');

        self::assertSame(1, $updater->calls, 'the updater endpoint drives Update License');
        self::assertSame(0, $this->verifier->calls, 'the verify endpoint is not used for the update');
        self::assertTrue($status->pro);
        self::assertTrue($this->repository->state->lifetime);
        self::assertSame(9, $this->repository->state->licenseVersion);
        self::assertSame('DOC-SIG==', $this->repository->state->signature);
        self::assertSame(['recovery', 'scheduled_backups'], $this->repository->state->features);
        self::assertSame(0, $this->repository->state->toArray()['license_expires_at']);
        self::assertTrue($this->repository->state->toArray()['license_lifetime']);
    }

    #[Test]
    public function anUnreachableUpdaterPreservesTheStoredLicense(): void
    {
        $previous = new LicenseState('GOOD-PRO-NOT-REAL', $this->now->getTimestamp() - 3600, null, 'example.com', 'pro', LicenseValidationStatus::Valid, null, [], false, null, true);
        $this->repository->state = $previous;
        $updater = new StubLicenseUpdater();
        $updater->next = LicenseVerificationResult::unreachable('down');
        $clock = new class($this->now) implements ClockInterface {
            public function __construct(private readonly \DateTimeImmutable $now) {}
            public function now(): \DateTimeImmutable { return $this->now; }
        };
        $manager = new LicenseManager($this->repository, $this->verifier, $clock, null, null, null, null, null, $updater);

        $status = $manager->activate('', 'example.com');

        self::assertSame('GOOD-PRO-NOT-REAL', $this->repository->state->key);
        self::assertTrue($status->licensed);
    }
}

final class InMemoryLicenseRepository implements LicenseStateRepositoryInterface
{
    public LicenseState $state;
    public bool $cleared = false;
    public function __construct() { $this->state = LicenseState::unlicensed(); }
    public function load(): LicenseState { return $this->state; }
    public function save(LicenseState $state): void { $this->state = $state; }
    public function clear(): void { $this->cleared = true; $this->state = LicenseState::unlicensed(); }
}

final class StubLicenseVerifier implements LicenseVerifierInterface
{
    public LicenseVerificationResult $next;
    public string $domain = '';
    public string $lastKey = '';
    public int $calls = 0;
    public function __construct() { $this->next = LicenseVerificationResult::denied(); }
    public function verify(string $licenseKey, string $domain, string $requiredPackage = ''): LicenseVerificationResult
    {
        $this->calls++;
        $this->domain = $domain;
        $this->lastKey = $licenseKey;
        return $this->next;
    }
}

final class StubLicenseUpdater implements LicenseUpdaterInterface
{
    public LicenseVerificationResult $next;
    public string $lastKey = '';
    public int $calls = 0;
    public function __construct() { $this->next = LicenseVerificationResult::denied(); }
    public function update(string $licenseKey, string $domain, string $requiredPackage = ''): LicenseVerificationResult
    {
        $this->calls++;
        $this->lastKey = $licenseKey;
        return $this->next;
    }
}
