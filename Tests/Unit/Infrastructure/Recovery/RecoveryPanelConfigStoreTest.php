<?php

declare(strict_types=1);

/*
 * This file is part of the "Guardian for TYPO3" extension for TYPO3 CMS.
 *
 * @author    V&T Innovations Team
 * @license   LGPL-3.0-or-later
 * @copyright V&T Innovations 2026 - 2028
 */

namespace Vtinnovations\GuardianTypo3\Tests\Unit\Infrastructure\Recovery;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Vtinnovations\GuardianTypo3\Domain\Exception\GuardianException;
use Vtinnovations\GuardianTypo3\Domain\Recovery\PanelFilename;
use Vtinnovations\GuardianTypo3\Infrastructure\Recovery\RecoveryPanelConfigStore;

final class RecoveryPanelConfigStoreTest extends TestCase
{
    private RecoveryPanelConfigStore $store;
    private string $base;

    protected function setUp(): void
    {
        $this->base = sys_get_temp_dir() . '/guardian-cfg-' . bin2hex(random_bytes(6));
        $this->store = new RecoveryPanelConfigStore(new FakeWorkingDirectory($this->base));
    }

    #[Test]
    public function panelIsDisabledByDefault(): void
    {
        self::assertFalse($this->store->isEnabled());
        self::assertSame(PanelFilename::DEFAULT, $this->store->filename());
    }

    #[Test]
    public function persistsEnabledStateAndValidatedFilename(): void
    {
        $this->store->setEnabled(true);
        $saved = $this->store->setFilename('rescue.php');
        self::assertSame('rescue.php', $saved);

        $fresh = new RecoveryPanelConfigStore(new FakeWorkingDirectory($this->base));
        self::assertTrue($fresh->isEnabled());
        self::assertSame('rescue.php', $fresh->filename());
    }

    #[Test]
    public function rejectsAnInvalidFilename(): void
    {
        $this->expectException(GuardianException::class);
        $this->store->setFilename('../evil.php');
    }

    #[Test]
    public function auditLogRecordsEventsWithoutSecrets(): void
    {
        $this->store->audit('token.generated', ['who' => 'admin']);
        $tail = $this->store->auditTail(10);
        self::assertNotEmpty($tail);
        self::assertStringContainsString('token.generated', end($tail));
    }
}
