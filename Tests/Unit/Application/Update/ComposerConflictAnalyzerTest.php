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
use Vtinnovations\GuardianTypo3\Application\Update\ComposerConflictAnalyzer;

final class ComposerConflictAnalyzerTest extends TestCase
{
    private ComposerConflictAnalyzer $analyzer;

    protected function setUp(): void
    {
        $this->analyzer = new ComposerConflictAnalyzer();
    }

    #[Test]
    public function classifiesADependencyConflictWithDetailsAndRecommendations(): void
    {
        $output = <<<'OUT'
        ./composer.json has been updated
        Running composer update georgringer/news
        Your requirements could not be resolved to an installable set of packages.

          Problem 1
            - Root composer.json requires georgringer/news ^1.0 -> satisfiable by georgringer/news[1.0.0].
            - georgringer/news 1.0.0 requires typo3/cms-core ^13.4 but the project uses typo3/cms-core v14.0.0.
        OUT;

        $result = $this->analyzer->analyze(2, $output);

        self::assertSame('composer_dependency_conflict', $result['error_code']);
        self::assertNotEmpty($result['details']);
        // The real Composer explanation lines are preserved (not a bare "Error").
        self::assertTrue((bool) array_filter($result['details'], static fn (string $d): bool => str_contains($d, 'requires typo3/cms-core')));
        self::assertContains('rec_select_older_version', $result['recommendations']);
        self::assertContains('rec_update_conflicting_first', $result['recommendations']);
    }

    #[Test]
    public function classifiesAuthenticationFailure(): void
    {
        $result = $this->analyzer->analyze(1, 'Could not authenticate against repo.example.com (HTTP 401)');
        self::assertSame('composer_auth_error', $result['error_code']);
        self::assertContains('rec_check_auth', $result['recommendations']);
    }

    #[Test]
    public function classifiesNetworkFailure(): void
    {
        $result = $this->analyzer->analyze(1, 'curl error 6 while downloading: Could not resolve host: repo.packagist.org');
        self::assertSame('composer_network_error', $result['error_code']);
    }

    #[Test]
    public function fallsBackToAGenericComposerErrorWithSomeDetail(): void
    {
        $result = $this->analyzer->analyze(1, "Something unexpected happened\nin composer");
        self::assertSame('composer_error', $result['error_code']);
        self::assertNotEmpty($result['details']);
    }

    #[Test]
    public function redactsCredentialsFromDetails(): void
    {
        $result = $this->analyzer->analyze(1, 'Downloading with token=SECRET-abc123 failed: could not resolve host');
        foreach ($result['details'] as $line) {
            self::assertStringNotContainsString('SECRET-abc123', $line);
        }
    }
}
