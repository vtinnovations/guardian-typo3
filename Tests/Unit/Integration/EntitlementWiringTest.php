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
use Symfony\Component\Yaml\Yaml;
use Vtinnovations\GuardianTypo3\Command\ReleaseCheckCommand;
use Vtinnovations\GuardianTypo3\Infrastructure\Registry\ServiceEndpoint;
use Vtinnovations\GuardianTypo3\Infrastructure\Version\ReleaseKeyring;
use Vtinnovations\GuardianTypo3\Middleware\RestEndpointMiddleware;

/**
 * That the pieces are actually connected, using only mechanisms that behave the
 * same on every TYPO3 version this extension declares.
 */
final class EntitlementWiringTest extends TestCase
{
    private function read(string $relative): string
    {
        return (string) file_get_contents(\dirname(__DIR__, 3) . '/' . $relative);
    }

    /**
     * @return array<string, mixed>
     */
    private function services(): array
    {
        $parsed = Yaml::parse($this->read('Configuration/Services.yaml'));
        self::assertIsArray($parsed);

        return $parsed['services'] ?? [];
    }

    #[Test]
    public function thePublicRouteIsReachableBeforeSiteResolution(): void
    {
        $config = $this->read('Configuration/RequestMiddlewares.php');

        self::assertStringContainsString("'frontend'", $config);
        self::assertStringContainsString('RestEndpointMiddleware::class', $config);
        // After URL normalisation (and therefore after core's host verification),
        // and before site/page resolution so a frontend 404 cannot shadow it.
        self::assertStringContainsString('typo3/cms-core/normalized-params-attribute', $config);
        self::assertStringContainsString('typo3/cms-frontend/site', $config);
        self::assertStringNotContainsString('LicenseUpdaterMiddleware', $config);
    }

    #[Test]
    public function theMiddlewareIsResolvableThroughTheContainer(): void
    {
        $services = $this->services();

        self::assertArrayHasKey(RestEndpointMiddleware::class, $services);
        self::assertTrue($services[RestEndpointMiddleware::class]['public'] ?? false);
    }

    #[Test]
    public function everyPortIsBoundToExactlyOneAdapter(): void
    {
        $services = $this->services();

        $expected = [
            'Vtinnovations\\GuardianTypo3\\Application\\Contract\\ServiceRecordStoreInterface'
                => 'Vtinnovations\\GuardianTypo3\\Infrastructure\\Configuration\\SealedRecordStore',
            'Vtinnovations\\GuardianTypo3\\Application\\Contract\\RecordExchangeInterface'
                => 'Vtinnovations\\GuardianTypo3\\Infrastructure\\Registry\\RecordExchangeClient',
            'Vtinnovations\\GuardianTypo3\\Application\\Contract\\InstallationIdentityInterface'
                => 'Vtinnovations\\GuardianTypo3\\Typo3\\Environment\\InstallationIdentity',
            'Vtinnovations\\GuardianTypo3\\Infrastructure\\Registry\\Transport\\PingTransportInterface'
                => 'Vtinnovations\\GuardianTypo3\\Infrastructure\\Registry\\Transport\\CurlPingTransport',
            'Vtinnovations\\GuardianTypo3\\Infrastructure\\Registry\\Transport\\ExchangeTransportInterface'
                => 'Vtinnovations\\GuardianTypo3\\Infrastructure\\Registry\\Transport\\RequestFactoryExchangeTransport',
        ];

        foreach ($expected as $port => $adapter) {
            self::assertArrayHasKey($port, $services, $port . ' is not bound');
            self::assertSame($adapter, $services[$port]['alias'] ?? null, $port);
        }
    }

    #[Test]
    public function noBindingStillPointsAtTheRemovedSubsystem(): void
    {
        $raw = $this->read('Configuration/Services.yaml');

        foreach (['Infrastructure\\License', 'Application\\License', 'Domain\\License', 'LicenseDigestCommand'] as $ghost) {
            self::assertStringNotContainsString($ghost, $raw, $ghost);
        }
    }

    #[Test]
    public function theReleaseCheckIsRegisteredAsAConsoleCommand(): void
    {
        $commands = $this->read('Configuration/Commands.php');
        self::assertStringContainsString("'guardian:release:check'", $commands);
        self::assertStringContainsString('ReleaseCheckCommand::class', $commands);

        $services = $this->services();
        self::assertContains(
            ['name' => 'console.command', 'command' => 'guardian:release:check'],
            $services[ReleaseCheckCommand::class]['tags'] ?? []
        );
    }

    #[Test]
    public function everyPortIntroducedForTheHostSetAndTheSessionEventIsBound(): void
    {
        // An unbound port is a container error on a customer's installation, not
        // here, so the bindings are asserted rather than assumed.
        $services = $this->services();

        foreach ([
            'Vtinnovations\\GuardianTypo3\\Application\\Contract\\ConfiguredHostsInterface'
                => 'Vtinnovations\\GuardianTypo3\\Typo3\\Environment\\SiteHostInventory',
            'Vtinnovations\\GuardianTypo3\\Application\\Contract\\SessionEntryClaimInterface'
                => 'Vtinnovations\\GuardianTypo3\\Typo3\\Environment\\BackendSessionClaim',
        ] as $port => $adapter) {
            self::assertArrayHasKey($port, $services, $port . ' is not bound');
            self::assertSame($adapter, $services[$port]['alias']);
        }
    }

    #[Test]
    public function theSharedScreenAndThisProductsSectionAreRetrievable(): void
    {
        // Both are resolved by name — the module by routing, the section by the
        // registry — so both must be public.
        $services = $this->services();

        foreach ([
            'Vtinnovations\\GuardianTypo3\\Controller\\Backend\\PackageOverviewController',
            'Vtinnovations\\GuardianTypo3\\Typo3\\Backend\\GuardianPackageSection',
        ] as $id) {
            self::assertArrayHasKey($id, $services, $id . ' is not registered');
            self::assertTrue($services[$id]['public'] ?? false, $id . ' must be publicly retrievable');
        }
    }

    #[Test]
    public function theReleaseCheckPassesForThisBuildAndFailsWithoutAKey(): void
    {
        // The shipped ring carries the approved key, so packaging is allowed.
        self::assertSame([], (new ReleaseKeyring())->productionReadiness());

        // The gate itself still refuses a build that has none.
        $problems = (new ReleaseKeyring([]))->productionReadiness();
        self::assertNotSame([], $problems);
        self::assertStringContainsString(ReleaseKeyring::NO_KEYS, implode(' ', $problems));
    }

    #[Test]
    public function theDestinationsAreExactlyTheTwoDocumentedOnes(): void
    {
        $endpoint = new ServiceEndpoint();

        self::assertSame('https://www.v-t.one/api/v1/verify', $endpoint->exchange());
        self::assertSame('https://www.v-t.one/rest/api/v1/log-envoke', $endpoint->signal());
        self::assertSame('/rest/api/v1/guardian-license-updater', $endpoint->inboundPath());
    }

    #[Test]
    public function noneOfTheDestinationsIsAReadableSingleLiteral(): void
    {
        $source = $this->read('Classes/Infrastructure/Registry/ServiceEndpoint.php');

        self::assertStringNotContainsString('https://www.v-t.one', $source);
        self::assertStringNotContainsString('www.v-t.one', $source);
    }

    #[Test]
    public function theSupportedPlatformRangeIsUnchanged(): void
    {
        $composer = json_decode($this->read('composer.json'), true);
        self::assertIsArray($composer);

        self::assertSame('^13.4.9 || ^14.0', $composer['require']['typo3/cms-core']);
        self::assertStringContainsString('8.2', $composer['require']['php']);
        // Signature verification is not optional, so libsodium is a hard dependency.
        self::assertArrayHasKey('ext-sodium', $composer['require']);
    }
}
