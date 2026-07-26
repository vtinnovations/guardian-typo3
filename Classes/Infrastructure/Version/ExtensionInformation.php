<?php

declare(strict_types=1);

/*
 * This file is part of the "Guardian for TYPO3" extension for TYPO3 CMS.
 *
 * @author    V&T Innovations Team
 * @license   LGPL-3.0-or-later
 * @copyright V&T Innovations 2026 - 2028
 */

namespace Vtinnovations\GuardianTypo3\Infrastructure\Version;

/**
 * Read-only identity of this extension, derived from its own composer.json.
 *
 * Single source of truth for the product name, package, extension key, license
 * and version constraints shown on the dashboard. Reading a shipped, static file
 * is safe and involves no CMS coupling.
 */
final class ExtensionInformation
{
    public const EXTENSION_KEY = 'guardian_typo3';
    public const PRODUCT_NAME = 'Guardian for TYPO3';
    public const COMPOSER_PACKAGE = 'vtinnovations/guardian-typo3';
    public const VERSION = '0.4.0';

    /** @var array<string, mixed>|null */
    private ?array $composer = null;

    private readonly string $extensionRootPath;

    /**
     * @param string|null $extensionRootPath Override for tests; defaults to the
     *                                        extension root derived from this file's location.
     */
    public function __construct(?string $extensionRootPath = null)
    {
        // Classes/Infrastructure/Version → three levels up is the extension root.
        $this->extensionRootPath = $extensionRootPath ?? \dirname(__DIR__, 3);
    }

    public function productName(): string
    {
        return self::PRODUCT_NAME;
    }

    public function version(): string
    {
        return (string) ($this->composer()['version'] ?? self::VERSION);
    }

    public function composerPackage(): string
    {
        return (string) ($this->composer()['name'] ?? self::COMPOSER_PACKAGE);
    }

    public function extensionKey(): string
    {
        $extra = $this->composer()['extra']['typo3/cms'] ?? [];

        return (string) ($extra['extension-key'] ?? self::EXTENSION_KEY);
    }

    public function license(): string
    {
        return (string) ($this->composer()['license'] ?? 'LGPL-3.0-or-later');
    }

    public function phpConstraint(): string
    {
        return (string) ($this->composer()['require']['php'] ?? 'unknown');
    }

    public function typo3Constraint(): string
    {
        return (string) ($this->composer()['require']['typo3/cms-core'] ?? 'unknown');
    }

    /**
     * @return array<string, string>
     */
    public function toArray(): array
    {
        return [
            'product_name' => $this->productName(),
            'composer_package' => $this->composerPackage(),
            'extension_key' => $this->extensionKey(),
            'license' => $this->license(),
            'php_constraint' => $this->phpConstraint(),
            'typo3_constraint' => $this->typo3Constraint(),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function composer(): array
    {
        if ($this->composer !== null) {
            return $this->composer;
        }

        $file = rtrim($this->extensionRootPath, '/') . '/composer.json';
        $raw = is_file($file) ? @file_get_contents($file) : false;
        $decoded = $raw !== false ? json_decode((string) $raw, true) : null;

        $this->composer = \is_array($decoded) ? $decoded : [];

        return $this->composer;
    }
}
