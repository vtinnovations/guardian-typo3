<?php

declare(strict_types=1);

/*
 * This file is part of the "Guardian for TYPO3" extension for TYPO3 CMS.
 *
 * @author    V&T Innovations Team
 * @license   LGPL-3.0-or-later
 * @copyright V&T Innovations 2026 - 2028
 */

namespace Vtinnovations\GuardianTypo3\Tests\Unit\Domain\Environment;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Vtinnovations\GuardianTypo3\Domain\Environment\HostIdentity;

/**
 * The exact-host rule, stated as tests.
 *
 * The protocol binds a record to one name. These cases pin down both halves of
 * that: representation may be canonicalised freely, and scope may not be widened
 * at all.
 */
final class HostIdentityTest extends TestCase
{
    #[Test]
    public function anIdenticalHostMatches(): void
    {
        self::assertTrue(HostIdentity::equals('example.com', 'example.com'));
        self::assertTrue(HostIdentity::equals('shop.example.com', 'shop.example.com'));
    }

    /**
     * @return list<array{0: string, 1: string, 2: string}>
     */
    public static function nonMatchingPairs(): array
    {
        return [
            ['example.com', 'www.example.com', 'apex does not cover its www form'],
            ['www.example.com', 'example.com', 'www form does not cover its apex'],
            ['example.com', 'shop.example.com', 'apex does not cover a child'],
            ['shop.example.com', 'example.com', 'a child does not cover its parent'],
            ['shop.example.com', 'blog.example.com', 'siblings are unrelated'],
            ['shop.example.com', 'admin.shop.example.com', 'a child does not cover its own child'],
            ['example.com', 'malicious-example.com', 'a shared suffix is not a relationship'],
            ['example.com', 'example.com.attacker.net', 'a prefix is not a relationship'],
            ['example.com', 'notexample.com', 'a substring is not a relationship'],
            ['example.co', 'example.com', 'a truncated name is a different name'],
        ];
    }

    #[Test]
    #[DataProvider('nonMatchingPairs')]
    public function relatedButDistinctHostsNeverMatch(string $licensed, string $current, string $why): void
    {
        self::assertFalse(HostIdentity::equals($licensed, $current), $why);
        self::assertFalse(HostIdentity::equals($current, $licensed), $why . ' (reversed)');
    }

    /**
     * @return list<array{0: string, 1: string}>
     */
    public static function equivalentRepresentations(): array
    {
        return [
            ['EXAMPLE.com', 'example.com'],
            ['Example.COM', 'example.com'],
            ['example.com.', 'example.com'],
            ['example.com:443', 'example.com'],
            ['example.com:8080', 'example.com'],
            ['  example.com  ', 'example.com'],
            ['https://example.com/some/path', 'example.com'],
            ['https://user:secret@example.com', 'example.com'],
            ['WWW.Example.COM.', 'www.example.com'],
            // A path or stray whitespace is transport noise, not part of a host.
            ['example.com/../evil', 'example.com'],
            ["example.com\n", 'example.com'],
        ];
    }

    #[Test]
    #[DataProvider('equivalentRepresentations')]
    public function normalisationChangesRepresentationOnly(string $raw, string $expected): void
    {
        self::assertSame($expected, HostIdentity::normalize($raw));
        self::assertTrue(HostIdentity::equals($raw, $expected));
    }

    #[Test]
    public function normalisationNeverStripsAWwwLabelOrCollapsesToARegistrableDomain(): void
    {
        self::assertSame('www.example.com', HostIdentity::normalize('WWW.EXAMPLE.COM'));
        self::assertSame('a.b.c.example.co.uk', HostIdentity::normalize('A.B.C.Example.CO.UK'));
    }

    #[Test]
    public function onlyOneTrailingDotIsRemoved(): void
    {
        self::assertSame('example.com', HostIdentity::normalize('example.com.'));
        // Two dots leave an empty label, which is not a host.
        self::assertSame('', HostIdentity::normalize('example.com..'));
    }

    /**
     * @return list<array{0: string}>
     */
    public static function unusableValues(): array
    {
        return [
            [''],
            ['   '],
            ['*'],
            ['*.example.com'],
            ['example.*'],
            ['.example.com'],
            ['example..com'],
            ['-example.com'],
            ['example-.com'],
            ['exa_mple.com'],
            ["exam\0ple.com"],
        ];
    }

    #[Test]
    #[DataProvider('unusableValues')]
    public function anUnusableValueYieldsNoIdentityAndNeverMatches(string $raw): void
    {
        self::assertSame('', HostIdentity::normalize($raw));
        self::assertFalse(HostIdentity::isValid($raw));
        self::assertFalse(HostIdentity::equals($raw, 'example.com'));
        self::assertFalse(HostIdentity::equals('example.com', $raw));
    }

    #[Test]
    public function aWildcardIsRejectedRatherThanInterpretedAsAScope(): void
    {
        self::assertSame('', HostIdentity::normalize('*.example.com'));
        self::assertFalse(HostIdentity::equals('*.example.com', 'shop.example.com'));
        self::assertFalse(HostIdentity::equals('*.example.com', 'example.com'));
    }

    #[Test]
    public function twoEmptyValuesDoNotMatchEachOther(): void
    {
        self::assertFalse(HostIdentity::equals('', ''));
        self::assertFalse(HostIdentity::equals('*', '*'));
    }

    #[Test]
    public function anInternationalisedNameCanonicalisesToItsAsciiFormWithoutChangingScope(): void
    {
        if (!\function_exists('idn_to_ascii')) {
            self::markTestSkipped('ext-intl is not available on this runtime.');
        }
        self::assertSame('xn--bcher-kva.example', HostIdentity::normalize('bücher.example'));
        self::assertTrue(HostIdentity::equals('BÜCHER.example', 'xn--bcher-kva.example'));
        // The ASCII form of one label still does not reach its parent.
        self::assertFalse(HostIdentity::equals('bücher.example', 'example'));
    }

    #[Test]
    public function addressLiteralsAreComparedExactlyAndAreNeverResolved(): void
    {
        self::assertSame('203.0.113.7', HostIdentity::normalize('203.0.113.7'));
        self::assertSame('203.0.113.7', HostIdentity::normalize('203.0.113.7:8080'));
        self::assertTrue(HostIdentity::equals('203.0.113.7', '203.0.113.7'));
        self::assertFalse(HostIdentity::equals('203.0.113.7', '203.0.113.8'));
        self::assertFalse(HostIdentity::equals('203.0.113.7', 'example.com'));

        self::assertSame('[2001:db8::1]', HostIdentity::normalize('[2001:DB8:0:0:0:0:0:1]'));
        self::assertTrue(HostIdentity::equals('[2001:db8::1]', '[2001:DB8::1]'));
        self::assertFalse(HostIdentity::equals('[2001:db8::1]', '[2001:db8::2]'));
    }

    #[Test]
    public function anOverlongNameIsRejected(): void
    {
        $label = str_repeat('a', 63);
        self::assertSame('', HostIdentity::normalize(str_repeat($label . '.', 5) . 'com'));
        self::assertSame('', HostIdentity::normalize(str_repeat('a', 64) . '.com'));
    }
}
