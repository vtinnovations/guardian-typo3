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
use Vtinnovations\GuardianTypo3\Domain\Environment\HostInventory;

/**
 * What the installation is configured to be, and how that meets what the vendor
 * signed.
 *
 * The intersection these tests describe is the activation decision: two sets that
 * a caller cannot reach into, and one exact member of both. Everything a licence
 * check must refuse — a neighbouring name, an apex standing in for a subdomain, a
 * host the operator configured but the vendor never authorised — is refused here.
 */
final class HostInventoryTest extends TestCase
{
    #[Test]
    public function configurationOrderIsKeptBecauseTheFirstEntryIsThePrimaryName(): void
    {
        $inventory = HostInventory::of(['shop.example.com', 'example.com', 'blog.example.com']);

        self::assertSame(['shop.example.com', 'example.com', 'blog.example.com'], $inventory->hosts);
        self::assertSame('shop.example.com', $inventory->primary());
    }

    #[Test]
    public function unusableAndRepeatedValuesAreDroppedWithoutChangingTheRest(): void
    {
        $inventory = HostInventory::of([
            'EXAMPLE.COM',
            'example.com.',        // the same host, written differently
            'example.com:8443',    // and again, with a port
            '*.example.com',       // not a host
            '',
            null,
            123,
            'shop.example.com',
        ]);

        self::assertSame(['example.com', 'shop.example.com'], $inventory->hosts);
    }

    #[Test]
    public function anEmptyInventoryIsARefusalRatherThanAPermission(): void
    {
        $inventory = HostInventory::empty();

        self::assertTrue($inventory->isEmpty());
        self::assertSame('', $inventory->primary());
        self::assertSame('', $inventory->select('example.com'));
        self::assertSame('', $inventory->match('example.com', ['example.com']));
        self::assertFalse($inventory->contains('example.com'));
    }

    #[Test]
    public function theHostBeingServedIsUsedForAnExchangeWhenItIsConfigured(): void
    {
        $inventory = HostInventory::of(['example.com', 'shop.example.com']);

        self::assertSame('shop.example.com', $inventory->select('shop.example.com'));
        self::assertSame('shop.example.com', $inventory->select('SHOP.EXAMPLE.COM.'));
    }

    #[Test]
    public function anUnconfiguredHostFallsBackToThePrimaryNameRatherThanItself(): void
    {
        // A backend reached under its own hostname verifies the site it belongs
        // to, deterministically, rather than verifying the backend's hostname.
        $inventory = HostInventory::of(['example.com', 'shop.example.com']);

        self::assertSame('example.com', $inventory->select('backend.internal'));
        self::assertSame('example.com', $inventory->select(''));
    }

    #[Test]
    public function theIntersectionIsOneExactMemberOfBothSets(): void
    {
        $inventory = HostInventory::of(['blog.example.com', 'shop.example.com']);

        self::assertSame('shop.example.com', $inventory->match('blog.example.com', ['shop.example.com']));
        self::assertSame('shop.example.com', $inventory->match('', ['a.test', 'shop.example.com']));
    }

    #[Test]
    public function theHostBeingServedWinsWhenItIsInBothSets(): void
    {
        // So that a request, a console command and a queue worker on the same
        // installation all settle on the same answer.
        $inventory = HostInventory::of(['a.example.com', 'b.example.com']);

        self::assertSame('b.example.com', $inventory->match('b.example.com', ['a.example.com', 'b.example.com']));
        self::assertSame('a.example.com', $inventory->match('', ['a.example.com', 'b.example.com']));
    }

    /**
     * @return list<array{0: list<string>, 1: list<string>, 2: string}>
     */
    public static function nonIntersections(): array
    {
        return [
            [['example.com'], ['www.example.com'], 'an apex is not its "www" form'],
            [['www.example.com'], ['example.com'], 'and the reverse is equally untrue'],
            [['shop.example.com'], ['example.com'], 'a parent does not cover a child'],
            [['example.com'], ['shop.example.com'], 'and a child does not cover a parent'],
            [['blog.example.com'], ['shop.example.com'], 'siblings are unrelated'],
            [['admin.shop.example.com'], ['shop.example.com'], 'nesting is not inheritance'],
            [['malicious-example.com'], ['example.com'], 'a longer name is not a suffix match'],
            [['example.com'], ['*.example.com'], 'a wildcard is not a host'],
            [['example.com'], [], 'nothing signed, nothing authorised'],
        ];
    }

    #[Test]
    #[DataProvider('nonIntersections')]
    public function hostsThatMerelyLookRelatedNeverIntersect(array $configured, array $signed, string $why): void
    {
        $inventory = HostInventory::of($configured);

        self::assertSame('', $inventory->match($configured[0] ?? '', $signed), $why);
    }

    #[Test]
    public function representationDifferencesStillMeet(): void
    {
        // Case, a trailing dot and a port are ways of writing the same name; they
        // are normalised on the way in and then compared exactly.
        $inventory = HostInventory::of(['EXAMPLE.COM.']);

        self::assertTrue($inventory->contains('example.com:443'));
        self::assertSame('example.com', $inventory->match('example.com', ['example.com']));
    }
}
