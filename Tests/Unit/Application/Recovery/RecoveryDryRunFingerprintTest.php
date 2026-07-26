<?php

declare(strict_types=1);

/*
 * This file is part of the "Guardian for TYPO3" extension for TYPO3 CMS.
 *
 * @author    V&T Innovations Team
 * @license   LGPL-3.0-or-later
 * @copyright V&T Innovations 2026 - 2028
 */

namespace Vtinnovations\GuardianTypo3\Tests\Unit\Application\Recovery;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Vtinnovations\GuardianTypo3\Application\Recovery\RecoveryDryRun;

final class RecoveryDryRunFingerprintTest extends TestCase
{
    #[Test]
    public function fingerprintIsStableForTheSameSelection(): void
    {
        $a = RecoveryDryRun::fingerprint('b1', ['database' => true, 'configuration' => true], 'rebuild');
        $b = RecoveryDryRun::fingerprint('b1', ['configuration' => true, 'database' => true], 'rebuild');
        self::assertSame($a, $b, 'order of components must not change the fingerprint');
    }

    #[Test]
    public function changingComponentsInvalidatesTheFingerprint(): void
    {
        $a = RecoveryDryRun::fingerprint('b1', ['database' => true], 'rebuild');
        $b = RecoveryDryRun::fingerprint('b1', ['database' => true, 'fileadmin' => true], 'rebuild');
        self::assertNotSame($a, $b);
    }

    #[Test]
    public function changingVendorStrategyInvalidatesTheFingerprint(): void
    {
        $a = RecoveryDryRun::fingerprint('b1', ['database' => true], 'rebuild');
        $b = RecoveryDryRun::fingerprint('b1', ['database' => true], 'archived');
        self::assertNotSame($a, $b);
    }

    #[Test]
    public function changingBackupInvalidatesTheFingerprint(): void
    {
        $a = RecoveryDryRun::fingerprint('b1', ['database' => true], 'rebuild');
        $b = RecoveryDryRun::fingerprint('b2', ['database' => true], 'rebuild');
        self::assertNotSame($a, $b);
    }

    #[Test]
    public function onlyTrueComponentsCountTowardsTheFingerprint(): void
    {
        $a = RecoveryDryRun::fingerprint('b1', ['database' => true, 'vendor' => false], 'rebuild');
        $b = RecoveryDryRun::fingerprint('b1', ['database' => true], 'rebuild');
        self::assertSame($a, $b);
    }
}
