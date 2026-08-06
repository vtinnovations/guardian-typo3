<?php

declare(strict_types=1);

/*
 * This file is part of the "Guardian for TYPO3" extension for TYPO3 CMS.
 *
 * @author    V&T Innovations Team
 * @license   LGPL-3.0-or-later
 * @copyright V&T Innovations 2026 - 2028
 */

namespace Vtinnovations\GuardianTypo3\Tests\Unit\Infrastructure\Configuration;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Vtinnovations\GuardianTypo3\Domain\Exception\GuardianException;
use Vtinnovations\GuardianTypo3\Infrastructure\Configuration\SealedRecordStore;
use Vtinnovations\GuardianTypo3\Tests\Unit\Support\InMemoryLockFactory;
use Vtinnovations\GuardianTypo3\Tests\Unit\Support\RecordPackageFactory;
use Vtinnovations\GuardianTypo3\Tests\Unit\Support\TempWorkingDirectory;

/**
 * The stored pair, and what happens to it when things go wrong.
 */
final class SealedRecordStoreTest extends TestCase
{
    private const NOW = 1784880600;

    private string $base;
    private TempWorkingDirectory $directory;
    private RecordPackageFactory $vendor;
    private SealedRecordStore $store;

    protected function setUp(): void
    {
        $this->base = sys_get_temp_dir() . '/guardian-store-' . bin2hex(random_bytes(6));
        $this->directory = new TempWorkingDirectory($this->base);
        $this->vendor = new RecordPackageFactory();
        $this->store = new SealedRecordStore(
            $this->directory,
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

    private function documentPath(): string
    {
        return $this->base . '/license.json';
    }

    private function envelopePath(): string
    {
        return $this->base . '/license.seal.json';
    }

    #[Test]
    public function nothingStoredMeansNoRecord(): void
    {
        $stored = $this->store->read(self::NOW);

        self::assertFalse($stored->exists());
        self::assertSame('absent', $stored->category);
    }

    #[Test]
    public function aStoredPairIsWrittenVerbatimAndReadsBack(): void
    {
        $package = $this->vendor->package();
        $this->store->replace($package['bytes'], $package['envelope'], self::NOW);

        // The document is on disk byte for byte, because the digest and the
        // signature are both defined over exactly these bytes.
        self::assertSame($package['bytes'], file_get_contents($this->documentPath()));

        $stored = $this->store->read(self::NOW);
        self::assertTrue($stored->exists());
        self::assertSame(7, $stored->record->version);
        self::assertSame('example.com', $stored->record->host);
    }

    #[Test]
    public function aHandEditedDocumentStopsVerifying(): void
    {
        $package = $this->vendor->package();
        $this->store->replace($package['bytes'], $package['envelope'], self::NOW);
        self::assertTrue($this->store->read(self::NOW)->exists());

        // Someone edits the file to award themselves another decade.
        file_put_contents(
            $this->documentPath(),
            str_replace('"license_expires_at":1815536000', '"license_expires_at":2130000000', $package['bytes'])
        );

        $stored = $this->store->read(self::NOW);
        self::assertFalse($stored->exists());
        self::assertSame('payload_digest_mismatch', $stored->category);
    }

    #[Test]
    public function recomputingTheStoredDigestDoesNotRestoreTrust(): void
    {
        $package = $this->vendor->package();
        $this->store->replace($package['bytes'], $package['envelope'], self::NOW);

        $forged = str_replace('"license_expires_at":1815536000', '"license_expires_at":2130000000', $package['bytes']);
        $envelope = $package['envelope'];
        $envelope['license_md5'] = hash('md5', $forged);

        file_put_contents($this->documentPath(), $forged);
        file_put_contents($this->envelopePath(), json_encode($envelope));

        self::assertFalse($this->store->read(self::NOW)->exists());
    }

    #[Test]
    public function removingEitherHalfOfThePairInvalidatesIt(): void
    {
        $package = $this->vendor->package();
        $this->store->replace($package['bytes'], $package['envelope'], self::NOW);

        unlink($this->envelopePath());
        self::assertFalse($this->store->read(self::NOW)->exists());
    }

    #[Test]
    public function anEnvelopeFromAnotherVersionOfTheDocumentIsRejected(): void
    {
        $first = $this->vendor->package(['license_version' => 7]);
        $second = $this->vendor->package(['license_version' => 9]);
        $this->store->replace($first['bytes'], $first['envelope'], self::NOW);

        // Swap in a genuinely signed envelope that belongs to a different document.
        file_put_contents($this->envelopePath(), json_encode($second['envelope']));

        self::assertFalse($this->store->read(self::NOW)->exists());
    }

    #[Test]
    public function aPackageThatDoesNotVerifyIsNeverWritten(): void
    {
        $package = $this->vendor->package();
        $envelope = $package['envelope'];
        $envelope['license_md5'] = hash('md5', 'something else');

        $this->expectException(GuardianException::class);
        try {
            $this->store->replace($package['bytes'], $envelope, self::NOW);
        } finally {
            self::assertFileDoesNotExist($this->documentPath());
            self::assertFileDoesNotExist($this->envelopePath());
        }
    }

    #[Test]
    public function aFailedReplacementLeavesThePreviousPairInPlace(): void
    {
        $good = $this->vendor->package(['license_version' => 7]);
        $this->store->replace($good['bytes'], $good['envelope'], self::NOW);

        $bad = $this->vendor->package(['license_version' => 9]);
        $brokenEnvelope = $bad['envelope'];
        $brokenEnvelope['signature'] = base64_encode(str_repeat("\x00", 64));

        try {
            $this->store->replace($bad['bytes'], $brokenEnvelope, self::NOW);
            self::fail('The broken package should not have been accepted.');
        } catch (GuardianException) {
            // expected
        }

        $stored = $this->store->read(self::NOW);
        self::assertTrue($stored->exists());
        self::assertSame(7, $stored->record->version, 'the previous record survived');
        self::assertSame($good['bytes'], file_get_contents($this->documentPath()));
    }

    #[Test]
    public function replacingLeavesNoTemporaryOrBackupFilesBehind(): void
    {
        $first = $this->vendor->package(['license_version' => 7]);
        $second = $this->vendor->package(['license_version' => 9]);
        $this->store->replace($first['bytes'], $first['envelope'], self::NOW);
        $this->store->replace($second['bytes'], $second['envelope'], self::NOW);

        $leftovers = array_values(array_filter(
            scandir($this->base) ?: [],
            static fn (string $name): bool => str_contains($name, '.staged') || str_contains($name, '.previous')
        ));

        self::assertSame([], $leftovers);
        self::assertSame(9, $this->store->read(self::NOW)->record->version);
    }

    #[Test]
    public function discardingRemovesBothHalves(): void
    {
        $package = $this->vendor->package();
        $this->store->replace($package['bytes'], $package['envelope'], self::NOW);

        $this->store->discard();

        self::assertFileDoesNotExist($this->documentPath());
        self::assertFileDoesNotExist($this->envelopePath());
        self::assertFalse($this->store->read(self::NOW)->exists());
    }

    #[Test]
    public function theObservedHostIsRememberedSeparatelyFromTheRecord(): void
    {
        self::assertSame('', $this->store->verifiedHost());

        $this->store->rememberVerifiedHost('EXAMPLE.com.');
        self::assertSame('example.com', $this->store->verifiedHost());

        // Moving the installation is observed on the next request.
        $this->store->rememberVerifiedHost('shop.example.com');
        self::assertSame('shop.example.com', $this->store->verifiedHost());
    }

    #[Test]
    public function anUnusableHostIsNeverRemembered(): void
    {
        $this->store->rememberVerifiedHost('example.com');
        $this->store->rememberVerifiedHost('*.example.com');

        self::assertSame('example.com', $this->store->verifiedHost());
    }

    #[Test]
    public function aPairCopiedFromAnotherInstallationStillCarriesItsOwnBinding(): void
    {
        // The pair verifies cryptographically wherever it is put — that is the
        // point of the signature — but it names the host it was issued for, and
        // that is what the entitlement check compares against.
        $package = $this->vendor->package(['license_domain' => 'other.example.com']);
        $this->store->replace($package['bytes'], $package['envelope'], self::NOW);

        $stored = $this->store->read(self::NOW);
        self::assertTrue($stored->exists());
        self::assertSame('other.example.com', $stored->record->host);
    }
}
