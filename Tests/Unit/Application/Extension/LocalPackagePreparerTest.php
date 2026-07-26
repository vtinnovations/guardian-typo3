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
use Vtinnovations\GuardianTypo3\Application\Extension\LocalPackagePreparer;
use Vtinnovations\GuardianTypo3\Domain\Exception\GuardianException;
use Vtinnovations\GuardianTypo3\Infrastructure\Extension\ManagedExtensionRegistry;
use Vtinnovations\GuardianTypo3\Tests\Unit\Infrastructure\Recovery\FakeWorkingDirectory;

final class LocalPackagePreparerTest extends TestCase
{
    private string $project;
    private string $staging;
    private ManagedExtensionRegistry $registry;

    protected function setUp(): void
    {
        $base = sys_get_temp_dir() . '/guardian-lpp-' . bin2hex(random_bytes(6));
        $this->project = $base . '/project';
        $this->staging = $base . '/staging/acme-blog';
        mkdir($this->project, 0o755, true);
        mkdir($this->staging, 0o755, true);
        file_put_contents($this->project . '/composer.json', json_encode(['require' => ['typo3/cms-core' => '^13.4']], \JSON_PRETTY_PRINT));
        file_put_contents($this->staging . '/composer.json', json_encode(['name' => 'acme/blog', 'type' => 'typo3-cms-extension']));
        file_put_contents($this->staging . '/ext_localconf.php', '<?php');
        $this->registry = new ManagedExtensionRegistry(new FakeWorkingDirectory($base . '/wd'));
    }

    protected function tearDown(): void
    {
        @exec('rm -rf ' . escapeshellarg(\dirname($this->project)));
    }

    private function preparer(): LocalPackagePreparer
    {
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

        return new LocalPackagePreparer($env, $this->registry);
    }

    private function local(): array
    {
        return ['staging_path' => $this->staging, 'target_dir' => 'acme_blog', 'composer_name' => 'acme/blog', 'version' => '2.4.8'];
    }

    #[Test]
    public function installCopiesAtomicallyAndAddsPathRepositoryAndRequirement(): void
    {
        $target = $this->preparer()->applyForInstall($this->local());

        self::assertSame($this->project . '/packages/acme_blog', $target);
        self::assertFileExists($target . '/composer.json');
        self::assertFileExists($target . '/ext_localconf.php');

        $composer = json_decode((string) file_get_contents($this->project . '/composer.json'), true);
        // Root requirement pinned to the exact approved version.
        self::assertSame('=2.4.8', $composer['require']['acme/blog']);
        $repo = $composer['repositories'][0];
        self::assertSame('path', $repo['type']);
        self::assertSame('packages/acme_blog', $repo['url']);
        // Canonical + a pinned STABLE version so Composer never treats it as dev-main.
        self::assertTrue($repo['canonical']);
        self::assertFalse($repo['options']['symlink']);
        self::assertSame(['acme/blog' => '2.4.8'], $repo['options']['versions']);
        // No leftover temp copy directory.
        self::assertCount(0, glob($this->project . '/packages/*.guardian-tmp-*') ?: []);
    }

    #[Test]
    public function installReliesOnAnExistingCanonicalPackagesGlobInsteadOfAddingASecondRepository(): void
    {
        // Reproduces the regression: the live composer.json already exposes the
        // destination through a canonical "packages/*" path repository. Adding a
        // second (also canonical) repository for the same package made Composer
        // resolve it twice and pick "dev-main". The preparer must instead rely on
        // the existing repository and NOT add a competing one.
        file_put_contents($this->project . '/composer.json', json_encode([
            'require' => ['typo3/cms-core' => '^13.4'],
            'repositories' => [['type' => 'path', 'url' => 'packages/*']],
        ], \JSON_PRETTY_PRINT));

        $this->preparer()->applyForInstall($this->local());

        $composer = json_decode((string) file_get_contents($this->project . '/composer.json'), true);
        // Still exactly ONE repository — the pre-existing "packages/*".
        self::assertCount(1, $composer['repositories']);
        self::assertSame('packages/*', $composer['repositories'][0]['url']);
        // The root requirement pins the exact stable version; the copied package's
        // own composer.json (pinned separately) makes "packages/*" resolve it.
        self::assertSame('=2.4.8', $composer['require']['acme/blog']);
    }

    #[Test]
    public function dryRunStillAddsAStagingRepositoryEvenWhenAPackagesGlobExists(): void
    {
        // The staging directory lives outside packages/, so a "packages/*" glob
        // does NOT cover it — the dry run must add its own staging path repo so
        // Composer can see the package at all.
        file_put_contents($this->project . '/composer.json', json_encode([
            'require' => ['typo3/cms-core' => '^13.4'],
            'repositories' => [['type' => 'path', 'url' => 'packages/*']],
        ], \JSON_PRETTY_PRINT));

        $restore = $this->preparer()->applyForDryRun($this->local());

        $composer = json_decode((string) file_get_contents($this->project . '/composer.json'), true);
        $urls = array_map(static fn ($r) => $r['url'], $composer['repositories']);
        self::assertContains('packages/*', $urls);
        self::assertContains($this->staging, $urls);
        $restore();
    }

    #[Test]
    public function dryRunModifiesThenRestoresComposerJsonExactly(): void
    {
        $original = (string) file_get_contents($this->project . '/composer.json');
        $restore = $this->preparer()->applyForDryRun($this->local());

        $modified = json_decode((string) file_get_contents($this->project . '/composer.json'), true);
        self::assertArrayHasKey('acme/blog', $modified['require']);
        // The dry-run path repository points at the staging directory (no copy),
        // pinned to the detected stable version.
        self::assertSame($this->staging, $modified['repositories'][0]['url']);
        self::assertTrue($modified['repositories'][0]['canonical']);
        self::assertSame(['acme/blog' => '2.4.8'], $modified['repositories'][0]['options']['versions']);

        $restore();
        self::assertSame($original, (string) file_get_contents($this->project . '/composer.json'));
        self::assertDirectoryDoesNotExist($this->project . '/packages/acme_blog');
    }

    #[Test]
    public function refusesToCopyAStagingTreeContainingASymlink(): void
    {
        if (@symlink('/etc/passwd', $this->staging . '/link') === false) {
            self::markTestSkipped('symlinks not supported here');
        }
        $this->expectException(GuardianException::class);
        $this->preparer()->applyForInstall($this->local());
    }

    #[Test]
    public function rejectsAnInvalidTargetDirectoryName(): void
    {
        $this->expectException(GuardianException::class);
        $this->preparer()->applyForInstall(['staging_path' => $this->staging, 'target_dir' => '../escape', 'composer_name' => 'acme/blog']);
    }

    #[Test]
    public function refusesToOverwriteAnExistingUnownedTargetDirectory(): void
    {
        mkdir($this->project . '/packages/acme_blog', 0o755, true);
        file_put_contents($this->project . '/packages/acme_blog/keep.txt', 'foreign');
        $this->expectException(GuardianException::class);
        $this->preparer()->applyForInstall($this->local());
    }

    #[Test]
    public function reclaimsAnExistingGuardianOwnedDirectoryOnReinstall(): void
    {
        $target = $this->project . '/packages/acme_blog';
        mkdir($target, 0o755, true);
        file_put_contents($target . '/stale.txt', 'old install');
        // Guardian owns exactly this path → a re-install reclaims it instead of
        // failing with "target directory already exists".
        $this->registry->record(['package' => 'acme/blog', 'path' => $target, 'checksum' => 'x', 'guardian_owned' => true]);

        $result = $this->preparer()->applyForInstall($this->local());

        self::assertSame($target, $result);
        self::assertFileExists($target . '/composer.json');
        self::assertFileDoesNotExist($target . '/stale.txt');
    }
}
