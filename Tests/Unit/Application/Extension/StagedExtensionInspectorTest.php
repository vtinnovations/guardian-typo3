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
use Vtinnovations\GuardianTypo3\Application\Extension\StagedExtensionInspector;
use Vtinnovations\GuardianTypo3\Application\Update\PackageManager;
use Vtinnovations\GuardianTypo3\Domain\Clock\ClockInterface;
use Vtinnovations\GuardianTypo3\Domain\Filesystem\PathNormalizer;
use Vtinnovations\GuardianTypo3\Infrastructure\Composer\PathRepositoryInspector;
use Vtinnovations\GuardianTypo3\Infrastructure\Update\UpdateJobStore;
use Vtinnovations\GuardianTypo3\Tests\Unit\Infrastructure\Recovery\FakeWorkingDirectory;

final class StagedExtensionInspectorTest extends TestCase
{
    private string $base;

    protected function setUp(): void
    {
        $this->base = sys_get_temp_dir() . '/guardian-staged-' . bin2hex(random_bytes(6));
        mkdir($this->base, 0o755, true);
    }

    protected function tearDown(): void
    {
        @exec('rm -rf ' . escapeshellarg($this->base));
    }

    private function inspector(): StagedExtensionInspector
    {
        $project = $this->base . '/project';
        @mkdir($project . '/vendor/composer', 0o755, true);
        $env = new class($project) implements ProjectEnvironmentInterface {
            public function __construct(private readonly string $p) {}
            public function typo3Version(): string { return '13.4.9'; }
            public function projectPath(): string { return $this->p; }
            public function varPath(): string { return $this->p . '/var'; }
            public function publicPath(): string { return $this->p . '/public'; }
            public function isComposerMode(): bool { return true; }
            public function phpVersion(): string { return \PHP_VERSION; }
            public function loadedPhpExtensions(): array { return []; }
        };
        $clock = new class implements ClockInterface {
            public function now(): \DateTimeImmutable { return new \DateTimeImmutable('2026-07-24T00:00:00+00:00'); }
        };
        $store = new UpdateJobStore(new FakeWorkingDirectory($this->base . '/wd'), $clock);
        $extState = new class implements \Vtinnovations\GuardianTypo3\Application\Contract\Typo3ExtensionStateInterface {
            public function isAvailable(): bool { return false; }
            public function isActive(string $extensionKey): bool { return true; }
            public function isProtected(string $extensionKey): bool { return false; }
            public function deactivate(string $extensionKey): void {}
            public function activate(string $extensionKey): void {}
        };
        $packages = new PackageManager($env, new PathRepositoryInspector(new PathNormalizer()), $store, $extState);

        return new StagedExtensionInspector($packages);
    }

    private function stage(string $name): string
    {
        $dir = $this->base . '/' . $name;
        mkdir($dir, 0o755, true);

        return $dir;
    }

    #[Test]
    public function detectsAComposerReadyExtension(): void
    {
        $root = $this->stage('extracted1');
        file_put_contents($root . '/composer.json', json_encode([
            'name' => 'acme/blog',
            'type' => 'typo3-cms-extension',
            'version' => '1.5.0',
            'require' => ['typo3/cms-core' => '^13.4', 'php' => '>=8.2'],
            'autoload' => ['psr-4' => ['Acme\\Blog\\' => 'Classes/']],
            'extra' => ['typo3/cms' => ['extension-key' => 'acme_blog']],
        ]));

        $r = $this->inspector()->inspect($root);
        self::assertSame('acme/blog', $r['composer_name']);
        self::assertSame('acme_blog', $r['extension_key']);
        self::assertSame('1.5.0', $r['version']);
        self::assertSame('^13.4', $r['typo3_constraint']);
        self::assertContains('Acme\\Blog\\', $r['namespaces']);
        self::assertTrue($r['installable']);
        self::assertSame([], $r['reasons']);
    }

    #[Test]
    public function toleratesASingleWrapperDirectory(): void
    {
        $outer = $this->stage('extracted2');
        mkdir($outer . '/acme-blog', 0o755, true);
        file_put_contents($outer . '/acme-blog/composer.json', json_encode(['name' => 'acme/blog', 'type' => 'typo3-cms-extension', 'extra' => ['typo3/cms' => ['extension-key' => 'acme_blog']]]));

        // The version comes from the upload filename fallback (content_blocks_2.4.8).
        $r = $this->inspector()->inspect($outer, 'acme_blog_2.0.0.zip');
        self::assertSame('acme/blog', $r['composer_name']);
        self::assertSame('acme-blog', $r['root_relative']);
        self::assertSame('2.0.0', $r['version']);
        self::assertTrue($r['installable']);
    }

    #[Test]
    public function detectsVersionFromExtEmconfWhenComposerJsonHasNone(): void
    {
        // Mirrors content_blocks: composer.json has NO version; ext_emconf.php does.
        $root = $this->stage('cb');
        file_put_contents($root . '/composer.json', json_encode(['name' => 'friendsoftypo3/content-blocks', 'type' => 'typo3-cms-extension', 'extra' => ['typo3/cms' => ['extension-key' => 'content_blocks']]]));
        file_put_contents($root . '/ext_emconf.php', "<?php\n\$EM_CONF['content_blocks'] = ['version' => '2.4.8'];\n");

        $r = $this->inspector()->inspect($root);
        self::assertSame('2.4.8', $r['version']);
        self::assertNotContains('extension_version_unknown', $r['reasons']);
    }

    #[Test]
    public function detectsVersionFromTheFilenameWhenNoMetadataHasOne(): void
    {
        $root = $this->stage('cb2');
        file_put_contents($root . '/composer.json', json_encode(['name' => 'friendsoftypo3/content-blocks', 'type' => 'typo3-cms-extension', 'extra' => ['typo3/cms' => ['extension-key' => 'content_blocks']]]));

        $r = $this->inspector()->inspect($root, 'content_blocks_2.4.8.zip');
        self::assertSame('2.4.8', $r['version']);
    }

    #[Test]
    public function blocksWhenTheVersionCannotBeDetermined(): void
    {
        $root = $this->stage('nov');
        file_put_contents($root . '/composer.json', json_encode(['name' => 'acme/blog', 'type' => 'typo3-cms-extension', 'extra' => ['typo3/cms' => ['extension-key' => 'acme_blog']]]));

        $r = $this->inspector()->inspect($root); // no version anywhere
        self::assertNull($r['version']);
        self::assertContains('extension_version_unknown', $r['reasons']);
        self::assertFalse($r['installable']);
    }

    #[Test]
    public function legacyExtensionIsWrapperGeneratable(): void
    {
        $root = $this->stage('my_legacy');
        file_put_contents($root . '/ext_emconf.php', "<?php\n\$EM_CONF['my_legacy'] = ['version' => '1.2.3', 'constraints' => ['depends' => ['typo3' => '13.4.0-13.4.99', 'php' => '8.2.0-8.3.99']]];\n");

        $r = $this->inspector()->inspect($root);
        self::assertTrue($r['legacy']);
        self::assertTrue($r['wrapper_generatable']);
        self::assertSame('local/my_legacy', $r['composer_name']);
        self::assertSame('my_legacy', $r['extension_key']);
    }

    #[Test]
    public function rejectsMultipleUnrelatedRoots(): void
    {
        $outer = $this->stage('extracted3');
        mkdir($outer . '/ext_a', 0o755, true);
        mkdir($outer . '/ext_b', 0o755, true);
        file_put_contents($outer . '/ext_a/ext_emconf.php', "<?php\n");
        file_put_contents($outer . '/ext_b/ext_emconf.php', "<?php\n");

        $r = $this->inspector()->inspect($outer);
        self::assertContains('multiple_roots', $r['reasons']);
        self::assertFalse($r['installable']);
    }

    #[Test]
    public function rejectsAPackageThatWouldOverwriteGuardian(): void
    {
        $root = $this->stage('extracted4');
        file_put_contents($root . '/composer.json', json_encode(['name' => 'vtinnovations/guardian-typo3', 'type' => 'typo3-cms-extension']));
        $r = $this->inspector()->inspect($root);
        self::assertContains('would_overwrite_guardian', $r['reasons']);
        self::assertFalse($r['installable']);
    }

    #[Test]
    public function rejectsCoreConflict(): void
    {
        $root = $this->stage('extracted5');
        file_put_contents($root . '/composer.json', json_encode(['name' => 'typo3/cms-belog', 'type' => 'typo3-cms-framework']));
        $r = $this->inspector()->inspect($root);
        self::assertContains('conflicts_typo3_core', $r['reasons']);
    }

    #[Test]
    public function flagsSuspiciousExecutablesUnderPublic(): void
    {
        $root = $this->stage('extracted6');
        file_put_contents($root . '/composer.json', json_encode(['name' => 'acme/blog', 'type' => 'typo3-cms-extension', 'extra' => ['typo3/cms' => ['extension-key' => 'acme_blog']]]));
        mkdir($root . '/Resources/Public', 0o755, true);
        file_put_contents($root . '/Resources/Public/evil.php', '<?php');

        $r = $this->inspector()->inspect($root);
        self::assertNotEmpty($r['suspicious_files']);
        self::assertContains('suspicious_files', $r['reasons']);
        self::assertFalse($r['installable']);
    }

    #[Test]
    public function rejectsMalformedComposerJson(): void
    {
        $root = $this->stage('extracted7');
        file_put_contents($root . '/composer.json', '{ this is not json');
        $r = $this->inspector()->inspect($root);
        self::assertContains('invalid_composer_json', $r['reasons']);
    }
}
