<?php

declare(strict_types=1);

/*
 * This file is part of the "Guardian for TYPO3" extension for TYPO3 CMS.
 *
 * @author    V&T Innovations Team
 * @license   LGPL-3.0-or-later
 * @copyright V&T Innovations 2026 - 2028
 */

namespace Vtinnovations\GuardianTypo3\Tests\Unit\Infrastructure\Update;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Vtinnovations\GuardianTypo3\Infrastructure\Update\Typo3ReleaseDiscovery;

/**
 * Covers the parsing/normalisation that previously discarded valid release data:
 * strict stable normalisation, per-source schema parsers, and version ordering.
 * The HTTP layer is not exercised here (no network); it is validated by the
 * fixed URLs, TLS/redirect/timeout options in the service itself.
 */
final class Typo3ReleaseDiscoveryTest extends TestCase
{
    private function service(): Typo3ReleaseDiscovery
    {
        return (new \ReflectionClass(Typo3ReleaseDiscovery::class))->newInstanceWithoutConstructor();
    }

    private function call(string $method, mixed ...$args): mixed
    {
        $m = new \ReflectionMethod(Typo3ReleaseDiscovery::class, $method);
        $m->setAccessible(true);

        return $m->invoke($this->service(), ...$args);
    }

    #[Test]
    #[DataProvider('stableVersions')]
    public function acceptsAndNormalisesStableVersions(string $input, string $expected): void
    {
        self::assertSame($expected, $this->call('normalizeStable', $input));
    }

    /** @return array<string, array{string, string}> */
    public static function stableVersions(): array
    {
        return [
            'plain' => ['13.4.9', '13.4.9'],
            'v-prefix' => ['v13.4.9', '13.4.9'],
            'composer-normalized 4-part' => ['13.4.9.0', '13.4.9'],
            'next major' => ['v14.0.1', '14.0.1'],
        ];
    }

    #[Test]
    #[DataProvider('nonStableVersions')]
    public function rejectsDevAliasAndPreReleaseVersions(string $input): void
    {
        self::assertNull($this->call('normalizeStable', $input));
    }

    /** @return array<string, array{string}> */
    public static function nonStableVersions(): array
    {
        return [
            'rc' => ['13.5.0-rc1'],
            'beta' => ['14.0.0-beta1'],
            'alpha' => ['14.0.0-alpha2'],
            'dev branch' => ['dev-main'],
            'x-dev' => ['13.4.x-dev'],
            'alias' => ['13.4.x'],
            'two-part' => ['13.4'],
            'empty' => [''],
            'garbage' => ['latest'],
        ];
    }

    #[Test]
    public function parsesTheOfficialReleaseApiArraySchema(): void
    {
        $data = [
            ['version' => '13.4.9', 'type' => 'regular'],
            ['version' => '13.4.12', 'type' => 'security'],
            ['version' => 'v14.0.1', 'type' => 'regular'],
            ['version' => '14.0.0-rc1', 'type' => 'development'], // rejected
            ['no_version' => true],                               // ignored
        ];

        self::assertSame(['13.4.9', '13.4.12', '14.0.1'], $this->call('parseOfficial', $data));
    }

    #[Test]
    public function parsesThePackagistP2Schema(): void
    {
        $data = ['packages' => ['typo3/cms-core' => [
            ['version' => 'v13.4.12', 'require' => ['php' => '^8.2']],
            ['version' => 'v13.4.9'],
            ['version' => 'dev-main'],   // rejected
        ]]];

        self::assertSame(['13.4.12', '13.4.9'], $this->call('parsePackagist', $data));
    }

    #[Test]
    public function packagistParserIgnoresAMissingCorePackage(): void
    {
        self::assertSame([], $this->call('parsePackagist', ['packages' => ['typo3/cms-backend' => []]]));
    }

    #[Test]
    public function highestUsesSemanticOrderingNotStringOrdering(): void
    {
        // Naive string sort would rank "13.4.9" above "13.4.12".
        self::assertSame('13.4.12', $this->call('highest', ['13.4.9', '13.4.12', '13.4.10']));
        self::assertNull($this->call('highest', []));
    }
}
