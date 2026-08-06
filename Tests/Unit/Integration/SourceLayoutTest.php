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

/**
 * The structural rules, kept honest by a test rather than by a review note.
 *
 * Entitlement handling is deliberately spread across the directories this
 * extension already uses, so it cannot be located by a directory listing, cannot
 * be lifted out as one package, and cannot be disabled by deleting one thing.
 * A future change that quietly re-collects it — or that adds one obvious
 * subsystem folder back — fails here.
 *
 * The public route, the protocol field names and the administrator-facing labels
 * keep their licence wording, because those are contracts with the outside world.
 */
final class SourceLayoutTest extends TestCase
{
    private function root(): string
    {
        return \dirname(__DIR__, 3);
    }

    /**
     * @return list<string> repository-relative paths of every shipped class file
     */
    private function classFiles(): array
    {
        $files = [];
        $iterator = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($this->root() . '/Classes'));
        foreach ($iterator as $file) {
            if ($file instanceof \SplFileInfo && $file->getExtension() === 'php') {
                $files[] = str_replace($this->root() . '/', '', $file->getPathname());
            }
        }
        sort($files);

        return $files;
    }

    #[Test]
    public function noDirectoryAnnouncesTheSubsystem(): void
    {
        $offenders = [];
        foreach ($this->classFiles() as $path) {
            if (preg_match(
                '#(^|/)(Licensing|License|Licence|Protection|AntiTamper|DRM|VtOne|VTone|Integrity|Security)(/|$)#',
                \dirname($path)
            ) === 1) {
                $offenders[] = $path;
            }
        }

        self::assertSame([], $offenders, 'a directory name announces the entitlement subsystem');
    }

    #[Test]
    public function noNamespaceAnnouncesTheSubsystem(): void
    {
        $offenders = [];
        foreach ($this->classFiles() as $path) {
            $source = (string) file_get_contents($this->root() . '/' . $path);
            if (preg_match('/^namespace\s+([^;]+);/m', $source, $matches) !== 1) {
                continue;
            }
            if (preg_match(
                '/\\\\(Licensing|License|Licence|Protection|AntiTamper|DRM|VtOne|VTone)(\\\\|$)/',
                $matches[1]
            ) === 1) {
                $offenders[] = $path . ' → ' . $matches[1];
            }
        }

        self::assertSame([], $offenders, 'a namespace announces the entitlement subsystem');
    }

    #[Test]
    public function noClassNameGivesTheImplementationAway(): void
    {
        $revealing = '/(class|interface|enum|trait)\s+(' . implode('|', [
            '(License|Licence)(Manager|Validator|Service|Repository|UpdaterController|Guard|State|Status|Store)',
            'TamperDetector',
            'AntiTamper',
            'ExpectedMd5',
            'ChecksumGuard',
            'VtoneLogger',
            'VtOneClient',
            'DrmManager',
        ]) . ')\b/';

        $offenders = [];
        foreach ($this->classFiles() as $path) {
            $source = (string) file_get_contents($this->root() . '/' . $path);
            if (preg_match($revealing, $source, $matches) === 1) {
                $offenders[] = $path . ' → ' . $matches[2];
            }
        }

        self::assertSame([], $offenders, 'a class name announces what it protects');
    }

    #[Test]
    public function theResponsibilitiesAreSpreadOverSeveralArchitecturalSeams(): void
    {
        // Each entry is one responsibility and the seam it lives in. They are
        // deliberately different seams: no single directory holds the flow.
        $placements = [
            'exact-host policy' => 'Classes/Domain/Environment/HostIdentity.php',
            'record document' => 'Classes/Domain/Configuration/ServiceRecord.php',
            'canonical signing input' => 'Classes/Infrastructure/Manifest/CanonicalForm.php',
            'signature verification' => 'Classes/Infrastructure/Manifest/DetachedSignature.php',
            'package opening and digest' => 'Classes/Infrastructure/Manifest/SealedPackage.php',
            'pinned keys' => 'Classes/Infrastructure/Version/ReleaseKeyring.php',
            'persistence and rollback' => 'Classes/Infrastructure/Configuration/SealedRecordStore.php',
            'replay and idempotency' => 'Classes/Infrastructure/Exchange/RequestJournal.php',
            'fixed destinations' => 'Classes/Infrastructure/Registry/ServiceEndpoint.php',
            'outbound protocol' => 'Classes/Infrastructure/Registry/RecordExchangeClient.php',
            'inbound authentication' => 'Classes/Typo3/Authorization/SignedRequestAuthorization.php',
            'installation identity' => 'Classes/Typo3/Environment/InstallationIdentity.php',
            'configured host inventory' => 'Classes/Typo3/Environment/SiteHostInventory.php',
            'host set policy' => 'Classes/Domain/Environment/HostInventory.php',
            'session claim' => 'Classes/Typo3/Environment/BackendSessionClaim.php',
            'session entry notice' => 'Classes/Infrastructure/Registry/EntryNotice.php',
            'shared screen host' => 'Classes/Controller/Backend/PackageOverviewController.php',
            'product registry' => 'Classes/Application/Environment/PackageDirectory.php',
            'evaluation' => 'Classes/Application/Environment/EntitlementReader.php',
            'feature gate' => 'Classes/Application/Environment/CapabilityAssertion.php',
            'administrator flows' => 'Classes/Application/Configuration/ActivationService.php',
            'inbound application' => 'Classes/Application/Configuration/RecordIntake.php',
            'public route' => 'Classes/Middleware/RestEndpointMiddleware.php',
        ];

        $directories = [];
        foreach ($placements as $responsibility => $path) {
            self::assertFileExists($this->root() . '/' . $path, $responsibility . ' is missing');
            $directories[\dirname($path)] = true;
        }

        self::assertGreaterThanOrEqual(
            8,
            \count($directories),
            'the responsibilities have been re-collected into too few places'
        );
    }

    #[Test]
    public function theSessionNoticeCarriesNeitherTheClaimNorTheTransport(): void
    {
        // The one place a full key leaves the server is deliberately three parts:
        // what decides it may be sent, what shapes it, and what sends it. None of
        // them can be removed to make the others send more.
        $notice = (string) file_get_contents($this->root() . '/Classes/Infrastructure/Registry/EntryNotice.php');

        self::assertStringNotContainsString('BE_USER', $notice, 'the notice must not read the session itself');
        self::assertStringNotContainsString('curl_', $notice, 'the notice must not open a connection itself');
        self::assertStringNotContainsString('setSessionData', $notice);

        $claim = (string) file_get_contents($this->root() . '/Classes/Typo3/Environment/BackendSessionClaim.php');
        foreach (['key', 'domain', 'license'] as $material) {
            self::assertStringNotContainsString(
                '$record->' . $material,
                $claim,
                'the claim must never see the material it gates'
            );
        }
        self::assertStringNotContainsString('v-t', $claim);
    }

    #[Test]
    public function theConfiguredHostsNeverComeFromARequest(): void
    {
        $inventory = (string) file_get_contents($this->root() . '/Classes/Typo3/Environment/SiteHostInventory.php');

        // The inventory is one half of the authorisation decision, so it must be
        // beyond the reach of whoever is asking.
        foreach (['$_SERVER', '$_GET', '$_POST', 'getHeaderLine', 'HTTP_HOST', 'X-Forwarded'] as $forbidden) {
            self::assertStringNotContainsString($forbidden, $inventory, $forbidden . ' must not reach the inventory');
        }
        self::assertStringContainsString('SiteFinder', $inventory, 'it must come from site configuration');
    }

    #[Test]
    public function theKeysTheDigestAndTheEndpointsDoNotShareAFile(): void
    {
        $keys = (string) file_get_contents($this->root() . '/Classes/Infrastructure/Version/ReleaseKeyring.php');
        $endpoints = (string) file_get_contents($this->root() . '/Classes/Infrastructure/Registry/ServiceEndpoint.php');
        $digest = (string) file_get_contents($this->root() . '/Classes/Infrastructure/Manifest/SealedPackage.php');

        self::assertStringNotContainsString('v-t', $keys, 'the key ring should not also carry the destinations');
        self::assertStringNotContainsString("hash('md5'", $keys);
        self::assertStringNotContainsString('sodium_crypto_sign', $endpoints, 'the destinations should not also verify');
        self::assertStringNotContainsString('sodium_crypto_sign', $digest, 'package opening delegates verification');
        self::assertStringNotContainsString('v-t', $digest);
    }

    #[Test]
    public function thePublicRouteHandlerStaysThin(): void
    {
        $source = (string) file_get_contents($this->root() . '/Classes/Middleware/RestEndpointMiddleware.php');

        // It shapes the request and delegates. It must not verify, hash, decide
        // entitlement, or touch the filesystem.
        foreach ([
            'sodium_crypto_sign',
            "hash('md5'",
            'base64_decode',
            'file_put_contents',
            'fopen',
            'unlink',
            'rename',
        ] as $forbidden) {
            self::assertStringNotContainsString($forbidden, $source, $forbidden . ' does not belong in the route handler');
        }

        self::assertLessThan(160, substr_count($source, "\n"), 'the route handler has grown beyond a thin adapter');
    }

    #[Test]
    public function theEndpointCannotBeTalkedIntoWritingAnArbitraryPath(): void
    {
        foreach ([
            'Classes/Middleware/RestEndpointMiddleware.php',
            'Classes/Application/Configuration/RecordIntake.php',
            'Classes/Infrastructure/Configuration/SealedRecordStore.php',
        ] as $path) {
            $source = (string) file_get_contents($this->root() . '/' . $path);
            foreach (['eval(', 'assert(', 'create_function', 'unserialize(', 'include $', 'require $'] as $forbidden) {
                self::assertStringNotContainsString($forbidden, $source, $forbidden . ' in ' . $path);
            }
        }

        // Every stored file name is a constant; none is built from input.
        $store = (string) file_get_contents($this->root() . '/Classes/Infrastructure/Configuration/SealedRecordStore.php');
        self::assertMatchesRegularExpression("/private const DOCUMENT = 'license\\.json';/", $store);
        self::assertStringNotContainsString('$request', $store);
    }

    #[Test]
    public function noSingleRegistrationTurnsEveryGateOff(): void
    {
        // The gate is asserted independently at each protected boundary, so
        // removing one call site cannot unlock the others.
        $guarded = [
            'Classes/Application/Backup/BackupService.php',
            'Classes/Application/Backup/ScheduledBackupRunner.php',
            'Classes/Application/Update/UpdateJobRunner.php',
            'Classes/Application/Recovery/RestoreService.php',
        ];

        foreach ($guarded as $path) {
            $source = (string) file_get_contents($this->root() . '/' . $path);
            self::assertStringContainsString(
                '$this->capability->require',
                $source,
                $path . ' no longer asserts its own entitlement'
            );
        }

        self::assertGreaterThanOrEqual(4, \count($guarded));
    }

    #[Test]
    public function theObsoleteSubsystemIsGoneWithNoStaleReferences(): void
    {
        foreach ([
            'Classes/Application/License',
            'Classes/Domain/License',
            'Classes/Infrastructure/License',
            'Classes/Middleware/LicenseUpdaterMiddleware.php',
            'Classes/Command/LicenseDigestCommand.php',
        ] as $path) {
            self::assertFileDoesNotExist($this->root() . '/' . $path);
        }

        $stale = [];
        $searchable = array_merge(
            $this->classFiles(),
            ['Configuration/Services.yaml', 'Configuration/RequestMiddlewares.php', 'Configuration/Commands.php']
        );
        foreach ($searchable as $path) {
            $source = (string) file_get_contents($this->root() . '/' . $path);
            foreach ([
                'Domain\\License',
                'Application\\License',
                'Infrastructure\\License',
                'LicenseManager',
                'LicenseGuard',
                'LicenseUpdaterMiddleware',
                'LicenseDigestCommand',
                'DomainNormalizer',
                'StoreIntegritySentinel',
                'SignatureSentinel',
            ] as $ghost) {
                if (str_contains($source, $ghost)) {
                    $stale[] = $path . ' → ' . $ghost;
                }
            }
        }

        self::assertSame([], $stale, 'a reference to the removed subsystem survived');
    }

    #[Test]
    public function noShippedCodeEverInjectsItsOwnKeyRing(): void
    {
        // The constructor takes keys so tests can build a ring in memory. If any
        // shipped class used that seam, an installation's trust anchor would come
        // from somewhere other than the pinned material, which is exactly what the
        // design forbids.
        $offenders = [];
        foreach ($this->classFiles() as $path) {
            $source = (string) file_get_contents($this->root() . '/' . $path);
            if (preg_match('/new\s+ReleaseKeyring\s*\(\s*[^)]/', $source) === 1) {
                $offenders[] = $path;
            }
        }

        self::assertSame([], $offenders, 'shipped code must construct the key ring with no arguments');
    }

    #[Test]
    public function theProductionKeyMaterialIsDeclaredInExactlyOnePlace(): void
    {
        $ring = (string) file_get_contents($this->root() . '/Classes/Infrastructure/Version/ReleaseKeyring.php');

        self::assertSame(1, substr_count($ring, 'private static function material()'));
        self::assertSame(1, substr_count($ring, 'private static function declaredFingerprints()'));

        // The material is never read from anywhere an installation could change.
        foreach (['getenv', '$_ENV', '$_SERVER', 'file_get_contents', 'GeneralUtility::getFileAbsFileName'] as $forbidden) {
            self::assertStringNotContainsString($forbidden, $ring, $forbidden . ' must not influence the key ring');
        }
    }

    #[Test]
    public function thePublicContractsThatMustKeepTheirWordingStillDo(): void
    {
        // These are agreements with the outside world, so the licence wording in
        // them is required rather than an oversight.
        $endpoint = (string) file_get_contents($this->root() . '/Classes/Infrastructure/Registry/ServiceEndpoint.php');
        self::assertStringContainsString('guardian-license-updater', $endpoint, 'the public route must keep its path');

        $record = (string) file_get_contents($this->root() . '/Classes/Domain/Configuration/ServiceRecord.php');
        foreach ([
            'license_key',
            'license_domain',
            'license_domains',
            'license_max_domains',
            'license_version',
            'free_available',
        ] as $field) {
            self::assertStringContainsString($field, $record, 'the protocol field ' . $field . ' must keep its name');
        }
    }
}
