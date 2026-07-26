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
use Vtinnovations\GuardianTypo3\Application\Contract\SystemLoggerInterface;
use Vtinnovations\GuardianTypo3\Infrastructure\License\StoreIntegritySentinel;
use Vtinnovations\GuardianTypo3\Tests\Unit\Infrastructure\Recovery\FakeWorkingDirectory;

final class StoreIntegritySentinelTest extends TestCase
{
    private function logger(): SystemLoggerInterface
    {
        return new class implements SystemLoggerInterface {
            public array $warnings = [];
            public function info(string $message, string $context = ''): void {}
            public function warning(string $message, string $context = ''): void { $this->warnings[] = $message; }
            public function error(string $message, string $context = ''): void {}
        };
    }

    #[Test]
    public function obfuscatedConstructionRoundTripsToTheExpectedDigest(): void
    {
        foreach (['d41d8cd98f00b204e9800998ecf8427e', hash('md5', 'guardian'), hash('md5', '{"a":1}')] as $md5) {
            self::assertSame($md5, StoreIntegritySentinel::decode(StoreIntegritySentinel::encode($md5)));
        }
    }

    #[Test]
    public function fragmentReconstructionMatchesTheDeveloperCommandLayout(): void
    {
        $md5 = hash('md5', 'frozen-license-bytes');
        $blob = StoreIntegritySentinel::encode($md5);

        // Mirror LicenseDigestCommand: split into 3, store reversed, order() reverses back.
        $logical = str_split($blob, (int) max(1, ceil(\strlen($blob) / 3)));
        $storage = array_reverse($logical);
        $reassembled = implode('', array_reverse($storage));

        self::assertSame($md5, StoreIntegritySentinel::decode($reassembled));
    }

    #[Test]
    public function judgeEnforcesRawBytesInConstantTime(): void
    {
        $judge = new \ReflectionMethod(StoreIntegritySentinel::class, 'judge');
        $judge->setAccessible(true);

        $raw = "{\"license_key\":\"x\"}\n";
        $expected = hash('md5', $raw);

        self::assertTrue($judge->invoke(null, '', $raw), 'unpinned always passes');
        self::assertTrue($judge->invoke(null, $expected, $raw), 'exact bytes pass');
        self::assertFalse($judge->invoke(null, $expected, $raw . ' '), 'a whitespace-only change fails');
        self::assertFalse($judge->invoke(null, $expected, '{"license_key":"y"}'), 'a changed value fails');
        self::assertFalse($judge->invoke(null, $expected, null), 'missing/unreadable file fails');
        self::assertFalse($judge->invoke(null, hash('md5', 'other'), $raw), 'wrong expected digest fails');
    }

    #[Test]
    public function unpinnedSentinelNeverBlocks(): void
    {
        $base = sys_get_temp_dir() . '/guardian-int-' . bin2hex(random_bytes(6));
        mkdir($base, 0o700, true);
        file_put_contents($base . '/license.json', '{"anything":true}');
        $sentinel = new StoreIntegritySentinel(new FakeWorkingDirectory($base), $this->logger());

        self::assertTrue($sentinel->intact(), 'default (unpinned) build must never block a live license');
    }
}
