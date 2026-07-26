<?php

declare(strict_types=1);

/*
 * This file is part of the "Guardian for TYPO3" extension for TYPO3 CMS.
 *
 * @author    V&T Innovations Team
 * @license   LGPL-3.0-or-later
 * @copyright V&T Innovations 2026 - 2028
 */

namespace Vtinnovations\GuardianTypo3\Tests\Unit\Infrastructure\Extension;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Vtinnovations\GuardianTypo3\Infrastructure\Extension\ManagedExtensionRegistry;
use Vtinnovations\GuardianTypo3\Tests\Unit\Infrastructure\Recovery\FakeWorkingDirectory;

final class ManagedExtensionRegistryTest extends TestCase
{
    private string $base;
    private ManagedExtensionRegistry $registry;

    protected function setUp(): void
    {
        $this->base = sys_get_temp_dir() . '/guardian-owned-' . bin2hex(random_bytes(6));
        $this->registry = new ManagedExtensionRegistry(new FakeWorkingDirectory($this->base));
    }

    protected function tearDown(): void
    {
        @exec('rm -rf ' . escapeshellarg($this->base));
    }

    #[Test]
    public function recordsAndRetrievesManagedOwnership(): void
    {
        $this->registry->record([
            'package' => 'acme/blog',
            'extension_key' => 'acme_blog',
            'version' => '1.0.0',
            'path' => '/app/packages/acme_blog',
            'checksum' => 'abc',
            'admin' => 'editor',
            'safety_backup' => 'backup-1',
        ]);

        $record = $this->registry->get('acme/blog');
        self::assertNotNull($record);
        self::assertSame('/app/packages/acme_blog', $record['path']);
        self::assertTrue($record['guardian_owned']);
        self::assertSame('backup-1', $record['safety_backup']);
        self::assertArrayHasKey('installed_at', $record);
    }

    #[Test]
    public function ownsDirectoryOnlyWhenPathMatchesAManagedRecord(): void
    {
        $this->registry->record(['package' => 'acme/blog', 'path' => '/app/packages/acme_blog', 'checksum' => 'x']);

        self::assertTrue($this->registry->ownsDirectory('acme/blog', '/app/packages/acme_blog'));
        // A different path for the same package is NOT owned.
        self::assertFalse($this->registry->ownsDirectory('acme/blog', '/app/packages/other'));
        // An unknown package is never owned (never delete unknown code).
        self::assertFalse($this->registry->ownsDirectory('vendor/unknown', '/app/packages/unknown'));
    }

    #[Test]
    public function forgetRemovesTheRecord(): void
    {
        $this->registry->record(['package' => 'acme/blog', 'path' => '/app/packages/acme_blog', 'checksum' => 'x']);
        $this->registry->forget('acme/blog');
        self::assertNull($this->registry->get('acme/blog'));
    }
}
