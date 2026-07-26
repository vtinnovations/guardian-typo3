<?php

declare(strict_types=1);

/*
 * This file is part of the "Guardian for TYPO3" extension for TYPO3 CMS.
 *
 * @author    V&T Innovations Team
 * @license   LGPL-3.0-or-later
 * @copyright V&T Innovations 2026 - 2028
 */

namespace Vtinnovations\GuardianTypo3\Tests\Unit\Application\Update;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Vtinnovations\GuardianTypo3\Application\Update\ComposerCommandFactory;
use Vtinnovations\GuardianTypo3\Domain\Exception\GuardianException;
use Vtinnovations\GuardianTypo3\Domain\Job\UpdateMode;

final class ComposerCommandFactoryTest extends TestCase
{
    private function factory(): ComposerCommandFactory
    {
        return new ComposerCommandFactory('/usr/bin/php', '/app/composer.phar', '/app');
    }

    #[Test]
    public function alwaysDrivesComposerThroughThePhpBinary(): void
    {
        $args = $this->factory()->forMode(UpdateMode::Full, [], false)->arguments;
        self::assertSame('/usr/bin/php', $args[0]);
        self::assertSame('/app/composer.phar', $args[1]);
        self::assertSame('update', $args[2]);
    }

    #[Test]
    public function fullModeUsesWithAllDependencies(): void
    {
        $args = $this->factory()->forMode(UpdateMode::Full, [], false)->arguments;
        self::assertContains('--with-all-dependencies', $args);
        self::assertContains('--no-scripts', $args);
        self::assertNotContains('--dry-run', $args);
    }

    #[Test]
    public function conservativeModeDoesNotUseWithAllDependencies(): void
    {
        $args = $this->factory()->forMode(UpdateMode::Patch, [], false)->arguments;
        self::assertNotContains('--with-all-dependencies', $args, 'Conservative must not do a full dependency update.');
        self::assertContains('--prefer-stable', $args);
    }

    #[Test]
    public function selectiveModePassesPackagesAsSeparateArgvElements(): void
    {
        $args = $this->factory()->forMode(UpdateMode::Selective, ['typo3/cms-core', 'typo3/cms-backend'], false)->arguments;
        self::assertContains('typo3/cms-core', $args);
        self::assertContains('typo3/cms-backend', $args);
        self::assertContains('--with-dependencies', $args);
        self::assertNotContains('--with-all-dependencies', $args);
    }

    #[Test]
    public function selectiveModeRejectsInjectedFlagsAsPackages(): void
    {
        $this->expectException(GuardianException::class);
        $this->factory()->forMode(UpdateMode::Selective, ['--no-scripts'], false);
    }

    #[Test]
    public function selectiveModeRequiresAtLeastOnePackage(): void
    {
        $this->expectException(GuardianException::class);
        $this->factory()->forMode(UpdateMode::Selective, [], false);
    }

    #[Test]
    public function dryRunAppendsDryRunFlag(): void
    {
        $args = $this->factory()->forMode(UpdateMode::Full, [], true)->arguments;
        self::assertContains('--dry-run', $args);
    }

    #[Test]
    public function onlyRealIgnorePlatformFlagsAreAccepted(): void
    {
        $args = $this->factory()->forMode(UpdateMode::Full, [], false, [
            '--ignore-platform-req=ext-intl',
            '; rm -rf /',
            '--evil',
        ])->arguments;
        self::assertContains('--ignore-platform-req=ext-intl', $args);
        self::assertNotContains('; rm -rf /', $args);
        self::assertNotContains('--evil', $args);
    }

    #[Test]
    public function outdatedIsReadOnlyJson(): void
    {
        $args = $this->factory()->outdated()->arguments;
        self::assertContains('outdated', $args);
        self::assertContains('--format=json', $args);
        self::assertNotContains('update', $args);
    }

    #[Test]
    public function removePassesPackagesAsSeparateArgvElementsThroughPhp(): void
    {
        $args = $this->factory()->remove(['acme/blog', 'acme/news'], false)->arguments;
        self::assertSame('/usr/bin/php', $args[0]);
        self::assertSame('/app/composer.phar', $args[1]);
        self::assertSame('remove', $args[2]);
        self::assertContains('acme/blog', $args);
        self::assertContains('acme/news', $args);
        self::assertContains('--no-scripts', $args);
        self::assertNotContains('--dry-run', $args);
    }

    #[Test]
    public function removeDryRunAppendsDryRunFlag(): void
    {
        $args = $this->factory()->remove(['acme/blog'], true)->arguments;
        self::assertContains('remove', $args);
        self::assertContains('--dry-run', $args);
    }

    #[Test]
    public function removeRejectsInjectedFlagsAsPackages(): void
    {
        $this->expectException(GuardianException::class);
        $this->factory()->remove(['--no-scripts'], false);
    }

    #[Test]
    public function removeRequiresAtLeastOnePackage(): void
    {
        $this->expectException(GuardianException::class);
        $this->factory()->remove([], false);
    }

    #[Test]
    public function requirePassesRequirementsAsSeparateArgvElementsThroughPhp(): void
    {
        $args = $this->factory()->require(['georgringer/news', 'vendor/pkg:^1.2'], false)->arguments;
        self::assertSame('/usr/bin/php', $args[0]);
        self::assertSame('/app/composer.phar', $args[1]);
        self::assertSame('require', $args[2]);
        self::assertContains('georgringer/news', $args);
        self::assertContains('vendor/pkg:^1.2', $args);
        self::assertContains('--with-all-dependencies', $args);
        self::assertNotContains('--dry-run', $args);
    }

    #[Test]
    public function requireDryRunAppendsDryRunFlag(): void
    {
        $args = $this->factory()->require(['georgringer/news'], true)->arguments;
        self::assertContains('--dry-run', $args);
    }

    #[Test]
    public function requireRejectsInjectedFlagAndBadConstraint(): void
    {
        $this->expectException(GuardianException::class);
        $this->factory()->require(['--no-scripts'], false);
    }

    #[Test]
    public function requireRequiresAtLeastOneRequirement(): void
    {
        $this->expectException(GuardianException::class);
        $this->factory()->require([], false);
    }

    #[Test]
    public function missingBinariesThrow(): void
    {
        $this->expectException(GuardianException::class);
        new ComposerCommandFactory('', '', '/app');
    }
}
