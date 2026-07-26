<?php

declare(strict_types=1);

/*
 * This file is part of the "Guardian for TYPO3" extension for TYPO3 CMS.
 *
 * @author    V&T Innovations Team
 * @license   LGPL-3.0-or-later
 * @copyright V&T Innovations 2026 - 2028
 */

namespace Vtinnovations\GuardianTypo3\Domain\Recovery;

use Vtinnovations\GuardianTypo3\Domain\Exception\GuardianException;

/**
 * Pure, filesystem-free validation of the standalone recovery-panel filename.
 *
 * The filename becomes a public entrypoint (public/<filename>), so it is tightly
 * constrained: a single basename, `.php` extension, only [A-Za-z0-9._-], and it
 * may never collide with TYPO3/webserver entrypoints or well-known project files.
 * Because it is pure it is fully unit-testable and shared by the backend
 * endpoint, the deployer and the tests.
 */
final class PanelFilename
{
    public const DEFAULT = '_guardian-recovery.php';

    private const PATTERN = '/^[A-Za-z0-9._-]{1,60}\.php$/';

    /** Names that must never be used as the panel filename. */
    private const FORBIDDEN = [
        'index.php',
        'install.php',
        'typo3',
        'typo3.php',
        'phpinfo.php',
        'info.php',
        'composer.php',
        'autoload.php',
        '.htaccess',
        'web.config',
    ];

    private function __construct(public readonly string $value)
    {
    }

    public static function isValid(string $name): bool
    {
        try {
            self::fromString($name);

            return true;
        } catch (GuardianException) {
            return false;
        }
    }

    /**
     * @throws GuardianException with a precise, non-sensitive reason
     */
    public static function fromString(string $name): self
    {
        $name = trim($name);
        if ($name === '') {
            throw new GuardianException('The panel filename must not be empty.');
        }
        if (str_contains($name, "\0")) {
            throw new GuardianException('The panel filename must not contain null bytes.');
        }
        if (str_contains($name, '/') || str_contains($name, '\\')) {
            throw new GuardianException('The panel filename must be a bare basename (no directories).');
        }
        if (str_contains($name, '..')) {
            throw new GuardianException('The panel filename must not contain "..".');
        }
        // basename() must not change the value — guards hidden traversal.
        if (basename($name) !== $name) {
            throw new GuardianException('The panel filename must be a bare basename.');
        }
        if (!str_ends_with(strtolower($name), '.php')) {
            throw new GuardianException('The panel filename must end in ".php".');
        }
        if (preg_match(self::PATTERN, $name) !== 1) {
            throw new GuardianException('The panel filename may only contain letters, digits, ".", "-" and "_".');
        }
        if (\in_array(strtolower($name), self::FORBIDDEN, true)) {
            throw new GuardianException('That filename is reserved and cannot be used for the recovery panel.');
        }

        return new self($name);
    }
}
