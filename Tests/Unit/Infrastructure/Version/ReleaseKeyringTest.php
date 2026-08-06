<?php

declare(strict_types=1);

/*
 * This file is part of the "Guardian for TYPO3" extension for TYPO3 CMS.
 *
 * @author    V&T Innovations Team
 * @license   LGPL-3.0-or-later
 * @copyright V&T Innovations 2026 - 2028
 */

namespace Vtinnovations\GuardianTypo3\Tests\Unit\Infrastructure\Version;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Vtinnovations\GuardianTypo3\Infrastructure\Version\ReleaseKeyring;
use Vtinnovations\GuardianTypo3\Infrastructure\Version\SigningKey;

/**
 * The trust anchor's own rules.
 *
 * A key that should not be trusted must not merely be preferred against — it must
 * be impossible to obtain from the ring at all, so no downstream code has the
 * opportunity to use it.
 */
final class ReleaseKeyringTest extends TestCase
{
    private function goodKeyBase64(): string
    {
        return base64_encode(sodium_crypto_sign_publickey(sodium_crypto_sign_keypair()));
    }

    #[Test]
    public function anApprovedKeyResolvesForItsIdentifierAndAlgorithm(): void
    {
        $key = SigningKey::pin('vtone-2026a', SigningKey::ALGORITHM_ED25519, $this->goodKeyBase64());
        self::assertNotNull($key);

        $ring = new ReleaseKeyring([$key]);
        self::assertFalse($ring->isEmpty());
        self::assertSame(['vtone-2026a'], $ring->keyIds());
        self::assertNotNull($ring->resolve('vtone-2026a', 'ed25519', SigningKey::PURPOSE_ENVELOPE, 1784880547));
        self::assertSame([], $ring->productionReadiness());
    }

    #[Test]
    public function anUnknownIdentifierResolvesToNothing(): void
    {
        $ring = new ReleaseKeyring([SigningKey::pin('vtone-2026a', 'ed25519', $this->goodKeyBase64())]);

        self::assertNull($ring->resolve('vtone-2027z', 'ed25519', SigningKey::PURPOSE_ANY, 1784880547));
        self::assertSame(ReleaseKeyring::UNKNOWN_KEY, $ring->refusalCategory('vtone-2027z'));
    }

    #[Test]
    public function anEmptyRingRefusesEverythingAndBlocksTheRelease(): void
    {
        $ring = new ReleaseKeyring([]);

        self::assertTrue($ring->isEmpty());
        self::assertNull($ring->resolve('vtone-2026a', 'ed25519', SigningKey::PURPOSE_ANY, 1784880547));
        self::assertSame(ReleaseKeyring::NO_KEYS, $ring->refusalCategory('vtone-2026a'));
        self::assertNotSame([], $ring->productionReadiness());
    }

    #[Test]
    public function thisBuildShipsTheApprovedVendorKeyAndMayBeReleased(): void
    {
        // The state of the shipped ring is an assertion, not a comment: it is what
        // decides whether any real packet can be verified at all. The identifier
        // and the fingerprint are the vendor's published ones, and the readiness
        // check covers length, algorithm and the fingerprint cross-check.
        $ring = new ReleaseKeyring();

        self::assertFalse($ring->isEmpty(), 'the shipped build must carry the approved verification key');
        self::assertSame(['vtone-2026a'], $ring->keyIds());
        self::assertSame([], $ring->productionReadiness());
        self::assertNotNull(
            $ring->resolve('vtone-2026a', 'ed25519', SigningKey::PURPOSE_ENVELOPE, 1784880547)
        );
    }

    #[Test]
    public function anEmptyRingIsStillAReleaseBlockerRatherThanARuntimeMode(): void
    {
        // The gate that produced the shipped state must keep working, so a build
        // that loses its key cannot be packaged and cannot verify anything.
        $ring = new ReleaseKeyring([]);

        self::assertTrue($ring->isEmpty());
        self::assertNull($ring->resolve('vtone-2026a', 'ed25519', SigningKey::PURPOSE_ANY, 1784880547));
        self::assertSame(ReleaseKeyring::NO_KEYS, $ring->refusalCategory('vtone-2026a'));
        self::assertContains(
            ReleaseKeyring::NO_KEYS . ': no verification key is pinned into this build.',
            $ring->productionReadiness()
        );
    }

    /**
     * @return list<array{0: string, 1: string}>
     */
    public static function unusableKeyMaterial(): array
    {
        return [
            ['', 'an empty string is not a key'],
            ['   ', 'blank material is not a key'],
            ['TODO', 'a reminder is not a key'],
            ['changeme', 'a placeholder is not a key'],
            ['REPLACE_WITH_REAL_KEY', 'a placeholder is not a key'],
            ['example-key-here', 'a placeholder is not a key'],
            ['not valid base64 !!!', 'invalid encoding is not a key'],
            ['c2hvcnQ=', 'a key of the wrong length is not a key'],
        ];
    }

    #[Test]
    #[DataProvider('unusableKeyMaterial')]
    public function unusableMaterialIsNeverPinned(string $material, string $why): void
    {
        self::assertNull(SigningKey::pin('vtone-2026a', 'ed25519', $material), $why);
    }

    #[Test]
    public function anAllZeroKeyIsRejected(): void
    {
        self::assertNull(SigningKey::pin(
            'vtone-2026a',
            'ed25519',
            base64_encode(str_repeat("\x00", SigningKey::expectedKeyLength()))
        ));
    }

    #[Test]
    public function anAlgorithmOutsideTheAllowlistIsNeverPinned(): void
    {
        foreach (['rsa', 'hmac-sha256', 'none', 'ES256', ''] as $algorithm) {
            self::assertNull(SigningKey::pin('vtone-2026a', $algorithm, $this->goodKeyBase64()), $algorithm);
        }
    }

    #[Test]
    public function aKeyDoesNotResolveForAnAlgorithmItWasNotPinnedFor(): void
    {
        $ring = new ReleaseKeyring([SigningKey::pin('vtone-2026a', 'ed25519', $this->goodKeyBase64())]);

        self::assertNull($ring->resolve('vtone-2026a', 'rsa', SigningKey::PURPOSE_ANY, 1784880547));
        self::assertNull($ring->resolve('vtone-2026a', 'none', SigningKey::PURPOSE_ANY, 1784880547));
    }

    #[Test]
    public function aKeyDoesNotResolveForAPurposeItWasNotPinnedFor(): void
    {
        $ring = new ReleaseKeyring([
            SigningKey::pin('vtone-2026a', 'ed25519', $this->goodKeyBase64(), [SigningKey::PURPOSE_ENVELOPE]),
        ]);

        self::assertNotNull($ring->resolve('vtone-2026a', 'ed25519', SigningKey::PURPOSE_ENVELOPE, 1784880547));
        self::assertNull($ring->resolve('vtone-2026a', 'ed25519', SigningKey::PURPOSE_REQUEST, 1784880547));
    }

    #[Test]
    public function aKeyIsUnavailableOutsideItsRotationWindow(): void
    {
        $ring = new ReleaseKeyring([
            SigningKey::pin('vtone-2026a', 'ed25519', $this->goodKeyBase64(), [SigningKey::PURPOSE_ANY], 1000, 2000),
        ]);

        self::assertNull($ring->resolve('vtone-2026a', 'ed25519', SigningKey::PURPOSE_ANY, 999), 'before activation');
        self::assertNotNull($ring->resolve('vtone-2026a', 'ed25519', SigningKey::PURPOSE_ANY, 1000), 'at activation');
        self::assertNotNull($ring->resolve('vtone-2026a', 'ed25519', SigningKey::PURPOSE_ANY, 1999), 'inside');
        self::assertNull($ring->resolve('vtone-2026a', 'ed25519', SigningKey::PURPOSE_ANY, 2000), 'at retirement');
        self::assertSame(ReleaseKeyring::KEY_NOT_USABLE, $ring->refusalCategory('vtone-2026a'));
    }

    #[Test]
    public function anImpossibleWindowIsNeverPinned(): void
    {
        self::assertNull(SigningKey::pin(
            'vtone-2026a',
            'ed25519',
            $this->goodKeyBase64(),
            [SigningKey::PURPOSE_ANY],
            2000,
            1000,
        ));
    }

    #[Test]
    public function conflictingDefinitionsOfOneIdentifierMakeItUnusable(): void
    {
        // Two different keys claiming the same name are ambiguous, so neither
        // is kept rather than one silently winning.
        $ring = new ReleaseKeyring([
            SigningKey::pin('vtone-2026a', 'ed25519', $this->goodKeyBase64()),
            SigningKey::pin('vtone-2026a', 'ed25519', $this->goodKeyBase64()),
        ]);

        self::assertTrue($ring->isEmpty());
        self::assertNull($ring->resolve('vtone-2026a', 'ed25519', SigningKey::PURPOSE_ANY, 1784880547));
    }

    #[Test]
    public function aDisposableKeyIdentifierBlocksTheRelease(): void
    {
        // A locally generated key verifies nothing the vendor ever signed. If one
        // reached customers the symptom would look like a broken licence rather
        // than a broken release, so the identifier is refused at build time.
        foreach (['vtone-test', 'vtone-dev', 'local-key', 'example-2026', 'vtone-staging', 'tmp-key'] as $id) {
            $ring = new ReleaseKeyring([SigningKey::pin($id, 'ed25519', $this->goodKeyBase64())]);

            self::assertNotSame([], $ring->productionReadiness(), $id);
            self::assertStringContainsString('disposable_key', implode(' ', $ring->productionReadiness()), $id);
        }
    }

    #[Test]
    public function aDisposableKeyStillVerifiesAtRuntimeSoOnlyPackagingIsBlocked(): void
    {
        // The rule is a packaging safeguard, not a cryptographic one: it must not
        // change what a running installation accepts.
        $ring = new ReleaseKeyring([SigningKey::pin('vtone-test', 'ed25519', $this->goodKeyBase64())]);

        self::assertNotNull($ring->resolve('vtone-test', 'ed25519', SigningKey::PURPOSE_ANY, 1784880547));
    }

    #[Test]
    public function anApprovedLookingIdentifierPassesTheReleaseCheck(): void
    {
        $ring = new ReleaseKeyring([SigningKey::pin('vtone-2026a', 'ed25519', $this->goodKeyBase64())]);

        self::assertSame([], $ring->productionReadiness());
    }

    #[Test]
    public function anIdentifierThatIsNotASelectorIsNeverPinned(): void
    {
        foreach (['', '   ', 'has space', "new\nline", str_repeat('a', 65), '../etc/passwd'] as $id) {
            self::assertNull(SigningKey::pin($id, 'ed25519', $this->goodKeyBase64()), var_export($id, true));
        }
    }
}
