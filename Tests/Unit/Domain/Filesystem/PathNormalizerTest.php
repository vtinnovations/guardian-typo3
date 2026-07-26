<?php

declare(strict_types=1);

/*
 * This file is part of the "Guardian for TYPO3" extension for TYPO3 CMS.
 *
 * @author    V&T Innovations Team
 * @license   LGPL-3.0-or-later
 * @copyright V&T Innovations 2026 - 2028
 */

namespace Vtinnovations\GuardianTypo3\Tests\Unit\Domain\Filesystem;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Vtinnovations\GuardianTypo3\Domain\Filesystem\PathNormalizer;

final class PathNormalizerTest extends TestCase
{
    private PathNormalizer $normalizer;

    protected function setUp(): void
    {
        $this->normalizer = new PathNormalizer();
    }

    /**
     * @return array<string, array{0: string, 1: string}>
     */
    public static function normalizationCases(): array
    {
        return [
            'collapses double slashes' => ['/var//guardian///runtime.json', '/var/guardian/runtime.json'],
            'drops single dots' => ['/var/./guardian', '/var/guardian'],
            'resolves parent segments' => ['/var/guardian/../guardian/runtime.json', '/var/guardian/runtime.json'],
            'parent cannot escape root' => ['/../../etc/passwd', '/etc/passwd'],
            'relative keeps leading parent' => ['../outside/file', '../outside/file'],
        ];
    }

    #[Test]
    #[DataProvider('normalizationCases')]
    public function normalizeResolvesLexically(string $input, string $expected): void
    {
        self::assertSame($expected, $this->normalizer->normalize($input));
    }

    #[Test]
    public function containedPathIsRecognised(): void
    {
        self::assertTrue($this->normalizer->isContained('/var/guardian', '/var/guardian/backup/2026'));
        self::assertTrue($this->normalizer->isContained('/var/guardian/', '/var/guardian'));
    }

    #[Test]
    public function traversalEscapeIsRejected(): void
    {
        self::assertFalse(
            $this->normalizer->isContained('/var/guardian', '/var/guardian/../../etc/passwd')
        );
    }

    #[Test]
    public function siblingPrefixIsNotConsideredContained(): void
    {
        // "/var/guardian-evil" must NOT be considered inside "/var/guardian".
        self::assertFalse($this->normalizer->isContained('/var/guardian', '/var/guardian-evil/x'));
    }

    #[Test]
    public function emptyBaseNeverContains(): void
    {
        self::assertFalse($this->normalizer->isContained('', '/anything'));
    }
}
