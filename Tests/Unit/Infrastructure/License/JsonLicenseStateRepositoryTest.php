<?php

declare(strict_types=1);

/*
 * This file is part of the "Guardian for TYPO3" extension for TYPO3 CMS.
 *
 * @author    V&T Innovations Team
 * @license   LGPL-3.0-or-later
 * @copyright V&T Innovations 2026 - 2028
 */

namespace Vtinnovations\GuardianTypo3\Tests\Unit\Infrastructure\License;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Vtinnovations\GuardianTypo3\Application\Contract\WorkingDirectoryProviderInterface;
use Vtinnovations\GuardianTypo3\Domain\License\LicenseState;
use Vtinnovations\GuardianTypo3\Domain\License\LicenseValidationStatus;
use Vtinnovations\GuardianTypo3\Infrastructure\License\JsonLicenseStateRepository;

final class JsonLicenseStateRepositoryTest extends TestCase
{
    private string $directory;
    private JsonLicenseStateRepository $repository;

    protected function setUp(): void
    {
        $this->directory = sys_get_temp_dir() . '/guardian-license-test-' . bin2hex(random_bytes(8));
        $paths = new class($this->directory) implements WorkingDirectoryProviderInterface {
            public function __construct(private readonly string $directory) {}
            public function exists(): bool { return is_dir($this->directory); }
            public function path(): string { return $this->directory; }
            public function resolve(string $relativePath): string { return $this->directory . '/' . ltrim($relativePath, '/'); }
            public function isWritable(): bool { return true; }
        };
        $this->repository = new JsonLicenseStateRepository($paths);
    }

    protected function tearDown(): void
    {
        $file = $this->directory . '/license.json';
        if (is_file($file)) { unlink($file); }
        if (is_dir($this->directory)) { rmdir($this->directory); }
    }

    #[Test]
    public function saveAndLoadRoundTripUsesPrivateFileMode(): void
    {
        $state = new LicenseState('TEST-NOT-REAL', 123, null, 'example.com', 'pro', LicenseValidationStatus::Valid);
        $this->repository->save($state);

        self::assertSame($state->toArray(), $this->repository->load()->toArray());
        self::assertSame(0o640, fileperms($this->directory . '/license.json') & 0o777);
        self::assertFileDoesNotExist($this->directory . '/license.json.tmp');
    }

    #[Test]
    public function malformedJsonAndInvalidFieldTypesFailClosed(): void
    {
        mkdir($this->directory, 0o750, true);
        file_put_contents($this->directory . '/license.json', '{broken');
        self::assertSame('', $this->repository->load()->key);

        file_put_contents($this->directory . '/license.json', '{"license_key":[]}');
        self::assertSame('', $this->repository->load()->key);
    }

    #[Test]
    public function olderLicenseFileWithoutIssuedDateRemainsReadable(): void
    {
        // A pre-existing license.json written before license_issued_at existed.
        mkdir($this->directory, 0o750, true);
        file_put_contents($this->directory . '/license.json', json_encode([
            'license_key' => 'LEGACY-NOT-REAL',
            'license_verified_at' => 1_700_000_000,
            'license_expires_at' => null,
            'license_domain' => 'example.com',
            'license_package' => 'pro',
            'validation_status' => 'valid',
        ]));

        $state = $this->repository->load();
        self::assertSame('LEGACY-NOT-REAL', $state->key);
        self::assertNull($state->issuedAt, 'a missing start date loads as null (open-ended)');
        self::assertSame('pro', $state->package);
    }

    #[Test]
    public function issuedAndExpiryDatesPersistAndReload(): void
    {
        $state = new LicenseState('DATED-NOT-REAL', 1_700_000_000, 1_800_000_000, 'example.com', 'pro', LicenseValidationStatus::Valid, 1_699_000_000);
        $this->repository->save($state);

        $loaded = $this->repository->load();
        self::assertSame(1_699_000_000, $loaded->issuedAt);
        self::assertSame(1_800_000_000, $loaded->expiresAt);
    }

    #[Test]
    public function clearRemovesTheStoredFile(): void
    {
        $this->repository->save(new LicenseState('TEST-NOT-REAL', 123, null, 'example.com', 'free', LicenseValidationStatus::Valid));
        $this->repository->clear();
        self::assertFileDoesNotExist($this->directory . '/license.json');
        self::assertSame('', $this->repository->load()->key);
    }

    #[Test]
    public function canonicalV2SchemaIsWrittenAndRoundTripsEveryField(): void
    {
        $state = new LicenseState(
            key: 'FULL-NOT-REAL',
            verifiedAt: 1_800_000_000,
            expiresAt: 1_900_000_000,
            domain: 'example.com',
            package: 'pro',
            validationStatus: LicenseValidationStatus::Valid,
            issuedAt: 1_690_000_000,
            features: ['recovery'],
            startsAt: 1_695_000_000,
            lifetime: false,
            licenseVersion: 5,
            signature: 'SIG==',
        );
        $this->repository->save($state);

        $raw = json_decode((string) file_get_contents($this->directory . '/license.json'), true);
        self::assertSame(2, $raw['schema_version']);
        self::assertSame('Guardian', $raw['project']);
        self::assertSame('guardian', $raw['project_slug']);
        self::assertSame(1_690_000_000, $raw['license_issued_at']);
        self::assertSame(1_695_000_000, $raw['license_starts_at']);
        self::assertSame(1_900_000_000, $raw['license_expires_at']);
        self::assertFalse($raw['license_lifetime']);
        self::assertSame('SIG==', $raw['signature']);

        self::assertSame($state->toArray(), $this->repository->load()->toArray());
    }

    #[Test]
    public function legacyFileMigratesToTheCanonicalSchemaOnLoad(): void
    {
        mkdir($this->directory, 0o750, true);
        // The exact current-file shape from the task (v1, null expiry).
        file_put_contents($this->directory . '/license.json', json_encode([
            'license_key' => 'VGR4L-LEGACY',
            'license_verified_at' => 1_784_880_547,
            'license_issued_at' => 1_784_880_547,
            'license_expires_at' => null,
            'license_domain' => 'brickie-typo3.vrisini.com',
            'license_package' => 'pro',
            'license_features' => [],
            'free_available' => false,
            'validation_status' => 'valid',
        ]));

        $reloaded = $this->repository->load()->toArray();
        self::assertSame(2, $reloaded['schema_version']);
        self::assertTrue($reloaded['license_lifetime'], 'null expiry on a verified key migrates to lifetime');
        self::assertSame(0, $reloaded['license_expires_at']);
        self::assertSame(1_784_880_547, $reloaded['license_issued_at']);
    }
}
