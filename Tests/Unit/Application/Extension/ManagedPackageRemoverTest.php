<?php

declare(strict_types=1);

/*
 * This file is part of the "Guardian for TYPO3" extension for TYPO3 CMS.
 *
 * @author    V&T Innovations Team
 * @license   LGPL-3.0-or-later
 * @copyright V&T Innovations 2026 - 2028
 */

namespace Vtinnovations\GuardianTypo3\Tests\Unit\Application\Extension;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Vtinnovations\GuardianTypo3\Application\Contract\ProjectEnvironmentInterface;
use Vtinnovations\GuardianTypo3\Application\Extension\ManagedPackageRemover;
use Vtinnovations\GuardianTypo3\Domain\Exception\GuardianException;
use Vtinnovations\GuardianTypo3\Infrastructure\Extension\ManagedExtensionRegistry;
use Vtinnovations\GuardianTypo3\Tests\Unit\Infrastructure\Recovery\FakeWorkingDirectory;

final class ManagedPackageRemoverTest extends TestCase
{
    private string $project;
    private ManagedExtensionRegistry $registry;
    private ManagedPackageRemover $remover;

    protected function setUp(): void
    {
        $base = sys_get_temp_dir() . '/guardian-mpr-' . bin2hex(random_bytes(6));
        $this->project = $base . '/project';
        mkdir($this->project . '/packages', 0o755, true);
        $wd = new FakeWorkingDirectory($base . '/wd');
        $this->registry = new ManagedExtensionRegistry($wd);
        $env = new class($this->project) implements ProjectEnvironmentInterface {
            public function __construct(private readonly string $p) {}
            public function typo3Version(): string { return '13.4.9'; }
            public function projectPath(): string { return $this->p; }
            public function varPath(): string { return $this->p . '/var'; }
            public function publicPath(): string { return $this->p . '/public'; }
            public function isComposerMode(): bool { return true; }
            public function phpVersion(): string { return \PHP_VERSION; }
            public function loadedPhpExtensions(): array { return []; }
        };
        $this->remover = new ManagedPackageRemover($env, $this->registry, $wd);
    }

    protected function tearDown(): void
    {
        @exec('rm -rf ' . escapeshellarg(\dirname($this->project)));
    }

    /** Install a managed directory with an ownership marker + registry record. */
    private function installManaged(string $package = 'friendsoftypo3/content-blocks', string $key = 'content_blocks', string $version = '2.4.8'): string
    {
        $dir = $this->project . '/packages/' . $key;
        mkdir($dir, 0o755, true);
        file_put_contents($dir . '/composer.json', json_encode(['name' => $package, 'type' => 'typo3-cms-extension', 'version' => $version]));
        $marker = $this->remover->writeOwnershipMarker($dir, $package);
        $this->registry->record([
            'package' => $package, 'extension_key' => $key, 'version' => $version,
            'path' => $dir, 'source_relative' => 'packages/' . $key, 'checksum' => 'abc',
            'guardian_owned' => true, 'ownership_marker' => $marker,
        ]);

        return $dir;
    }

    #[Test]
    public function persistsOwnershipAndVerifiesTheRemovalPlan(): void
    {
        $dir = $this->installManaged();
        $plan = $this->remover->plan('friendsoftypo3/content-blocks');

        self::assertTrue($plan['managed']);
        self::assertTrue($plan['ownership_verified']);
        self::assertSame('packages/content_blocks', $plan['source_relative']);
        self::assertSame($dir, $plan['path']);
        self::assertSame('content_blocks', $plan['target_dir']);
    }

    #[Test]
    public function refusesToVerifyWhenTheMarkerIsMissing(): void
    {
        $dir = $this->installManaged();
        unlink($dir . '/' . ManagedPackageRemover::MARKER_FILE);
        $plan = $this->remover->plan('friendsoftypo3/content-blocks');

        self::assertFalse($plan['ownership_verified']);
        self::assertSame('marker_mismatch', $plan['reason']);
    }

    #[Test]
    public function refusesToVerifyWhenTheIdentityNoLongerMatches(): void
    {
        $dir = $this->installManaged();
        // A different package replaced the directory.
        file_put_contents($dir . '/composer.json', json_encode(['name' => 'evil/other', 'type' => 'typo3-cms-extension']));
        $plan = $this->remover->plan('friendsoftypo3/content-blocks');

        self::assertFalse($plan['ownership_verified']);
        self::assertSame('identity_mismatch', $plan['reason']);
    }

    #[Test]
    public function removesOnlyTheRelevantVersionMappingLeavingOthersAndTheBroadRepo(): void
    {
        file_put_contents($this->project . '/composer.json', json_encode([
            'require' => ['friendsoftypo3/content-blocks' => '=2.4.8', 'acme/other' => '=1.0.0'],
            'repositories' => [
                ['type' => 'path', 'url' => 'packages/*'],
                ['type' => 'path', 'url' => 'packages/content_blocks', 'options' => ['symlink' => false, 'versions' => [
                    'friendsoftypo3/content-blocks' => '2.4.8',
                    'acme/other' => '1.0.0',
                ]]],
            ],
        ], \JSON_PRETTY_PRINT));

        $this->remover->removeVersionMapping('friendsoftypo3/content-blocks');

        $data = json_decode((string) file_get_contents($this->project . '/composer.json'), true);
        // Broad packages/* repo untouched.
        self::assertSame('packages/*', $data['repositories'][0]['url']);
        // Only the removed package is gone; the unrelated mapping remains.
        $versions = $data['repositories'][1]['options']['versions'];
        self::assertArrayNotHasKey('friendsoftypo3/content-blocks', $versions);
        self::assertSame(['acme/other' => '1.0.0'], $versions);
    }

    #[Test]
    public function quarantineMovesTheOwnedDirectoryAndCommitDeletesIt(): void
    {
        $dir = $this->installManaged();
        $plan = $this->remover->plan('friendsoftypo3/content-blocks');

        $quarantine = $this->remover->quarantine($plan, 'job123');
        self::assertDirectoryDoesNotExist($dir);
        self::assertDirectoryExists($quarantine);

        $this->remover->commitQuarantine('job123');
        self::assertDirectoryDoesNotExist($quarantine);
    }

    #[Test]
    public function restoreQuarantineMovesTheDirectoryBackOnFailure(): void
    {
        $dir = $this->installManaged();
        $plan = $this->remover->plan('friendsoftypo3/content-blocks');
        $quarantine = $this->remover->quarantine($plan, 'job9');

        $this->remover->restoreQuarantine($quarantine, $dir);
        self::assertDirectoryExists($dir);
        self::assertFileExists($dir . '/composer.json');
    }

    #[Test]
    public function neverQuarantinesADirectoryItDoesNotOwn(): void
    {
        // A directory that exists but has no ownership record.
        mkdir($this->project . '/packages/foreign', 0o755, true);
        $this->expectException(GuardianException::class);
        $this->remover->quarantine(['ownership_verified' => false, 'path' => $this->project . '/packages/foreign', 'package' => 'x/y'], 'j');
    }

    #[Test]
    public function removeOwnedDirectoryDeletesAndForgetsWhenVerified(): void
    {
        $dir = $this->installManaged();
        $plan = $this->remover->plan('friendsoftypo3/content-blocks');
        $this->remover->removeOwnedDirectory($plan);

        self::assertDirectoryDoesNotExist($dir);
        self::assertNull($this->registry->get('friendsoftypo3/content-blocks'));
    }

    #[Test]
    public function classifiesAVerifiedGuardianOrphan(): void
    {
        $this->installManaged();
        $c = $this->remover->classifyExistingDirectory('content_blocks', 'friendsoftypo3/content-blocks');

        self::assertSame('verified_guardian_orphan', $c['classification']);
        self::assertTrue($c['owned']);
    }

    #[Test]
    public function classifiesAConflictingForeignDirectory(): void
    {
        $dir = $this->project . '/packages/content_blocks';
        mkdir($dir, 0o755, true);
        file_put_contents($dir . '/composer.json', json_encode(['name' => 'someone/else', 'version' => '9.9.9']));

        $c = $this->remover->classifyExistingDirectory('content_blocks', 'friendsoftypo3/content-blocks');
        self::assertSame('conflicting', $c['classification']);
        self::assertFalse($c['owned']);
        self::assertSame('someone/else', $c['detected_name']);
    }

    #[Test]
    public function classifiesNoneWhenTheDirectoryIsAbsent(): void
    {
        $c = $this->remover->classifyExistingDirectory('content_blocks', 'friendsoftypo3/content-blocks');
        self::assertSame('none', $c['classification']);
    }
}
