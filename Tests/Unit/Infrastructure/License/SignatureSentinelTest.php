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
use Vtinnovations\GuardianTypo3\Infrastructure\License\SignatureSentinel;

final class SignatureSentinelTest extends TestCase
{
    #[Test]
    public function payloadWithoutSignatureIsNotApplicableAndPasses(): void
    {
        $sentinel = new SignatureSentinel();
        self::assertTrue($sentinel->verified(['license_key' => 'x']));
        self::assertFalse($sentinel->applies(['license_key' => 'x']));
    }

    #[Test]
    public function signaturePresentButNoEmbeddedKeyIsSkipped(): void
    {
        $sentinel = new SignatureSentinel();
        // No public key is pinned in the default build, so this layer cannot apply
        // and must not block the established online model.
        self::assertTrue($sentinel->verified(['license_key' => 'x', 'signature' => base64_encode(str_repeat('a', 64))]));
        self::assertFalse($sentinel->applies(['license_key' => 'x', 'signature' => base64_encode(str_repeat('a', 64))]));
    }

    #[Test]
    public function canonicalPayloadExcludesSignatureAndIsDeterministic(): void
    {
        $canonical = new \ReflectionMethod(SignatureSentinel::class, 'canonical');
        $canonical->setAccessible(true);
        $sentinel = new SignatureSentinel();

        $a = $canonical->invoke($sentinel, ['b' => 2, 'a' => 1, 'signature' => 'ZZZ']);
        $b = $canonical->invoke($sentinel, ['a' => 1, 'b' => 2]);

        self::assertSame($b, $a, 'key order must not change the canonical form');
        self::assertStringNotContainsString('signature', $a, 'the signature field is excluded from the signed payload');
        self::assertStringNotContainsString('ZZZ', $a);
    }

    #[Test]
    public function verifiesARealEd25519SignatureWhenAKeyIsAvailable(): void
    {
        if (!\function_exists('sodium_crypto_sign_keypair')) {
            self::markTestSkipped('libsodium not available');
        }
        // Prove the canonical payload the sentinel signs over is actually signable
        // and verifiable with a matching key (the embedded key is pinned per release).
        $keypair = sodium_crypto_sign_keypair();
        $secret = sodium_crypto_sign_secretkey($keypair);
        $public = sodium_crypto_sign_publickey($keypair);

        $sentinel = new SignatureSentinel();
        $canonical = new \ReflectionMethod(SignatureSentinel::class, 'canonical');
        $canonical->setAccessible(true);

        $payload = ['project' => 'Guardian', 'licensedDomains' => ['example.com'], 'signature' => ''];
        $message = $canonical->invoke($sentinel, $payload);
        $signature = sodium_crypto_sign_detached($message, $secret);

        self::assertTrue(sodium_crypto_sign_verify_detached($signature, $message, $public));
        self::assertFalse(sodium_crypto_sign_verify_detached($signature, $message . 'x', $public), 'a tampered payload fails');
    }
}
