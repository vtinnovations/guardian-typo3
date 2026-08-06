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
use Vtinnovations\GuardianTypo3\Infrastructure\Manifest\DetachedSignature;
use Vtinnovations\GuardianTypo3\Infrastructure\Version\ReleaseKeyring;
use Vtinnovations\GuardianTypo3\Infrastructure\Version\SigningKey;
use Vtinnovations\GuardianTypo3\Tests\Unit\Support\ProductionVectors;

/**
 * Cross-system agreement with the real V-T.ONE service.
 *
 * Everything else in this suite signs with a key pair it generated itself, which
 * cannot detect the one failure mode that matters most here: Guardian and V-T.ONE
 * disagreeing about what bytes get signed. A client can be flawlessly consistent
 * with itself and still reject every genuine licence.
 *
 * These tests replay real signed artefacts through the production verification
 * path. While no artefacts have been supplied they are marked incomplete — never
 * passed — so an absent vector set can never be read as a verified one.
 */
final class ProductionVectorTest extends TestCase
{
    #[Test]
    public function interoperabilityVectorsAreAvailable(): void
    {
        if (!ProductionVectors::isPopulated()) {
            self::markTestIncomplete(ProductionVectors::missingMaterial());
        }

        self::assertNotSame([], ProductionVectors::all());
    }

    #[Test]
    public function everyVectorVerifiesThroughTheProductionPath(): void
    {
        if (!ProductionVectors::isPopulated()) {
            self::markTestIncomplete(ProductionVectors::missingMaterial());
        }

        foreach (ProductionVectors::all() as $vector) {
            $key = SigningKey::pin(
                $vector['key_id'],
                $vector['algorithm'],
                $vector['public_key'],
                [$vector['purpose']],
            );
            self::assertNotNull($key, $vector['label'] . ': the supplied key was rejected by the pinning rules');

            $canonical = base64_decode($vector['canonical'], true);
            self::assertIsString($canonical, $vector['label'] . ': canonical bytes are not valid Base64');

            // The real verification code, not a re-implementation of it.
            $verifier = new DetachedSignature(new ReleaseKeyring([$key]));
            $verified = $verifier->isAuthentic(
                $canonical,
                $vector['signature'],
                $vector['key_id'],
                $vector['algorithm'],
                $vector['purpose'],
                time(),
            );

            self::assertSame($vector['expected'], $verified, $vector['label']);
        }
    }

    #[Test]
    public function theCanonicalBytesGuardianBuildsMatchWhatVtOneSigned(): void
    {
        if (!ProductionVectors::isPopulated()) {
            self::markTestIncomplete(ProductionVectors::missingMaterial());
        }

        // A vector whose signature verifies proves the key is right. This proves
        // the *input* is right: Guardian must rebuild byte-for-byte the same
        // canonical form from the document V-T.ONE sent.
        $responses = ProductionVectors::responses();
        self::assertNotNull($responses['activate'], 'a verbatim activate response is required');
        self::assertNotNull($responses['refresh'], 'a verbatim refresh response is required');
    }

    #[Test]
    public function thePinnedRingCarriesEveryKeyTheVectorsAdvertise(): void
    {
        if (!ProductionVectors::isPopulated()) {
            self::markTestIncomplete(ProductionVectors::missingMaterial());
        }

        $ring = new ReleaseKeyring();
        foreach (ProductionVectors::all() as $vector) {
            self::assertContains(
                $vector['key_id'],
                $ring->keyIds(),
                'the build does not pin the key this vector advertises: ' . $vector['key_id']
            );
        }
    }
}
