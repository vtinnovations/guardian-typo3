<?php

declare(strict_types=1);

/*
 * This file is part of the "Guardian for TYPO3" extension for TYPO3 CMS.
 *
 * @author    V&T Innovations Team
 * @license   LGPL-3.0-or-later
 * @copyright V&T Innovations 2026 - 2028
 */

namespace Vtinnovations\GuardianTypo3\Tests\Unit\Application\Backup;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Vtinnovations\GuardianTypo3\Application\Backup\ComponentSelection;
use Vtinnovations\GuardianTypo3\Domain\Backup\BackupComponent;

final class ComponentSelectionTest extends TestCase
{
    #[Test]
    public function baselineComponentsAreAlwaysSelected(): void
    {
        $selection = ComponentSelection::fromRequest([]);

        self::assertTrue($selection->isSelected(BackupComponent::ComposerJson));
        self::assertTrue($selection->isSelected(BackupComponent::ComposerLock));
        self::assertTrue($selection->isSelected(BackupComponent::Database));
    }

    #[Test]
    public function fileadminIsOnlyIncludedWhenExplicitlyTrue(): void
    {
        self::assertFalse(ComponentSelection::fromRequest([])->isSelected(BackupComponent::Fileadmin));
        self::assertFalse(ComponentSelection::fromRequest(['fileadmin' => 'true'])->isSelected(BackupComponent::Fileadmin));
        self::assertFalse(ComponentSelection::fromRequest(['fileadmin' => 1])->isSelected(BackupComponent::Fileadmin));
        self::assertTrue(ComponentSelection::fromRequest(['fileadmin' => true])->isSelected(BackupComponent::Fileadmin));
    }

    #[Test]
    public function optionalComponentsRequireStrictBooleanTrue(): void
    {
        $selection = ComponentSelection::fromRequest([
            'vendor' => true,
            'configuration' => false,
            'publicAssets' => 'yes',
        ]);

        self::assertTrue($selection->isSelected(BackupComponent::Vendor));
        self::assertFalse($selection->isSelected(BackupComponent::Configuration));
        self::assertFalse($selection->isSelected(BackupComponent::PublicAssets));
    }

    #[Test]
    public function miniProfileIsDatabaseAndComposerOnly(): void
    {
        $mini = ComponentSelection::mini();

        self::assertTrue($mini->isSelected(BackupComponent::Database));
        self::assertTrue($mini->isSelected(BackupComponent::ComposerJson));
        foreach (BackupComponent::optional() as $component) {
            self::assertFalse($mini->isSelected($component), $component->value . ' must be off for mini');
        }
    }

    #[Test]
    public function fullProfileEnablesTheGivenDirectories(): void
    {
        $full = ComponentSelection::forFull(['vendor' => true, 'fileadmin' => true, 'configuration' => false]);

        self::assertTrue($full->isSelected(BackupComponent::Vendor));
        self::assertTrue($full->isSelected(BackupComponent::Fileadmin));
        self::assertFalse($full->isSelected(BackupComponent::Configuration));
        self::assertTrue($full->isSelected(BackupComponent::Database), 'baseline still applies to full');
    }

    #[Test]
    public function selectedComponentsAndArrayAreConsistent(): void
    {
        $selection = ComponentSelection::fromRequest(['vendor' => true, 'fileadmin' => true]);
        $array = $selection->toArray();

        self::assertTrue($array['fileadmin']);
        self::assertContains(BackupComponent::Fileadmin, $selection->selectedComponents());
        self::assertNotContains(BackupComponent::PublicAssets, $selection->selectedComponents());
    }
}
