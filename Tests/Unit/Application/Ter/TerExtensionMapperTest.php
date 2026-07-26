<?php

declare(strict_types=1);

/*
 * This file is part of the "Guardian for TYPO3" extension for TYPO3 CMS.
 *
 * @author    V&T Innovations Team
 * @license   LGPL-3.0-or-later
 * @copyright V&T Innovations 2026 - 2028
 */

namespace Vtinnovations\GuardianTypo3\Tests\Unit\Application\Ter;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Vtinnovations\GuardianTypo3\Application\Ter\TerExtensionMapper;

final class TerExtensionMapperTest extends TestCase
{
    private TerExtensionMapper $mapper;

    protected function setUp(): void
    {
        $this->mapper = new TerExtensionMapper();
    }

    /**
     * @param array<string, mixed> $raw
     */
    private function map(array $raw, int $typo3 = 13, string $php = '8.2.0', bool $installed = false): array
    {
        return $this->mapper->map($raw, $typo3, $php, static fn (string $n): bool => $installed);
    }

    #[Test]
    public function resolvesComposerIdentityAndMarksInstallable(): void
    {
        $result = $this->map([
            'key' => 'news',
            'title' => 'News system',
            'current_version' => [
                'number' => '11.0.0',
                'composer_name' => 'georgringer/news',
                'typo3_versions' => [12, 13],
                'php_version' => '>=8.1',
                'upload_date' => '2026-01-01',
            ],
            'author' => ['name' => 'Georg Ringer'],
        ]);

        self::assertSame('georgringer/news', $result['composer_name']);
        self::assertTrue($result['composer_available']);
        self::assertSame('composer_identity_available', $result['composer_state']);
        self::assertTrue($result['typo3_compatible']);
        self::assertTrue($result['php_compatible']);
        self::assertSame('installable', $result['compatibility_state']);
        self::assertTrue($result['auto_installable']);
        self::assertNull($result['reason']);
    }

    #[Test]
    public function withoutAComposerNameItIsNotAutoInstallable(): void
    {
        $result = $this->map(['key' => 'legacy_ext', 'current_version' => ['number' => '1.0.0', 'typo3_versions' => [13]]]);
        self::assertFalse($result['composer_available']);
        self::assertSame('composer_identity_missing', $result['composer_state']);
        self::assertFalse($result['auto_installable']);
        self::assertSame('composer_identity_missing', $result['reason']);
        // A missing identity does not falsely claim incompatibility.
        self::assertSame('installable', $result['compatibility_state']);
    }

    #[Test]
    public function typo3IncompatibilityKeepsTheComposerIdentityAndDoesNotBecomeAnIdentityError(): void
    {
        // Mirrors content_defender: valid identity, but incompatible with TYPO3 13.
        $result = $this->map([
            'key' => 'content_defender',
            'current_version' => ['number' => '4.0.0', 'composer_name' => 'ichhabrecht/content-defender', 'typo3_versions' => [11, 12]],
        ], 13);
        self::assertSame('ichhabrecht/content-defender', $result['composer_name']);
        self::assertTrue($result['composer_available']);
        self::assertSame('composer_identity_available', $result['composer_state']);
        self::assertFalse($result['typo3_compatible']);
        self::assertSame('typo3_incompatible', $result['compatibility_state']);
        self::assertFalse($result['auto_installable']);
        self::assertSame('typo3_incompatible', $result['reason']);
    }

    #[Test]
    public function contentBlocksStaysInstallableWhenCompatible(): void
    {
        $result = $this->map([
            'key' => 'content_blocks',
            'current_version' => ['number' => '1.3.0', 'composer_name' => 'friendsoftypo3/content-blocks', 'typo3_versions' => [12, 13], 'php_version' => '>=8.1'],
        ], 13, '8.2.0');
        self::assertSame('friendsoftypo3/content-blocks', $result['composer_name']);
        self::assertSame('installable', $result['compatibility_state']);
        self::assertTrue($result['auto_installable']);
        self::assertNull($result['reason']);
    }

    #[Test]
    public function incompatiblePhpIsRejectedWithReason(): void
    {
        $result = $this->map([
            'key' => 'needs_php83',
            'current_version' => ['number' => '1.0.0', 'composer_name' => 'vendor/x', 'typo3_versions' => [13], 'php_version' => '>=8.3'],
        ], 13, '8.2.0');
        self::assertFalse($result['php_compatible']);
        self::assertSame('php_incompatible', $result['compatibility_state']);
        self::assertSame('php_incompatible', $result['reason']);
        self::assertTrue($result['composer_available']); // identity intact
    }

    #[Test]
    public function reDeriveAfterResolvingIdentityMakesACompatibleRowInstallable(): void
    {
        // A TER row that lacked a Composer identity but is TYPO3-compatible.
        $row = $this->map(['key' => 'content_blocks', 'current_version' => ['number' => '1.3.0', 'typo3_versions' => [13]]]);
        self::assertFalse($row['auto_installable']);
        self::assertSame('composer_identity_missing', $row['reason']);

        // Resolve identity from trusted metadata and re-derive: now installable,
        // WITHOUT losing the already-computed compatibility.
        $row['composer_name'] = 'friendsoftypo3/content-blocks';
        $fixed = $this->mapper->deriveState($row, static fn (string $n): bool => false);
        self::assertTrue($fixed['auto_installable']);
        self::assertNull($fixed['reason']);
        self::assertSame('composer_identity_available', $fixed['composer_state']);
    }

    #[Test]
    public function alreadyInstalledIsReportedAndNotOffered(): void
    {
        $result = $this->map([
            'key' => 'news',
            'current_version' => ['number' => '11.0.0', 'composer_name' => 'georgringer/news', 'typo3_versions' => [13]],
        ], 13, '8.2.0', true);
        self::assertTrue($result['already_installed']);
        self::assertFalse($result['auto_installable']);
        self::assertSame('already_installed', $result['reason']);
    }

    #[Test]
    public function unknownCompatibilityDoesNotBlockInstall(): void
    {
        // No typo3_versions and no php constraint → unknown (null), not a blocker.
        $result = $this->map(['key' => 'x', 'current_version' => ['number' => '1.0.0', 'composer_name' => 'vendor/x']]);
        self::assertNull($result['typo3_compatible']);
        self::assertNull($result['php_compatible']);
        self::assertTrue($result['auto_installable']);
    }

    #[Test]
    public function abandonedAndDeprecatedFlagsAreSurfaced(): void
    {
        $result = $this->map([
            'key' => 'x',
            'abandoned' => true,
            'deprecated' => true,
            'current_version' => ['number' => '1.0.0', 'composer_name' => 'vendor/x', 'typo3_versions' => [13]],
        ]);
        self::assertTrue($result['abandoned']);
        self::assertTrue($result['deprecated']);
    }
}
