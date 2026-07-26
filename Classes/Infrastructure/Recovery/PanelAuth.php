<?php

declare(strict_types=1);

/*
 * This file is part of the "Guardian for TYPO3" extension for TYPO3 CMS.
 *
 * @author    V&T Innovations Team
 * @license   LGPL-3.0-or-later
 * @copyright V&T Innovations 2026 - 2028
 */

namespace Vtinnovations\GuardianTypo3\Infrastructure\Recovery;

use Vtinnovations\GuardianTypo3\Application\Contract\WorkingDirectoryProviderInterface;
use Vtinnovations\GuardianTypo3\Domain\Exception\GuardianException;

/**
 * Access-token authority for the standalone recovery panel, ported from the
 * audited Contao PanelAuth.
 *
 * Two sources, in precedence order:
 *   1. the GUARDIAN_RECOVERY_TOKEN / VTINNOVATIONS_GUARDIAN_TOKEN env var,
 *   2. the auto-generated file var/guardian/access.token (0600).
 *
 * Tokens are 96 hex chars (48 random bytes) and compared with hash_equals().
 * The standalone panel reads the same file directly, so a token rotated here
 * takes effect there immediately.
 */
final class PanelAuth
{
    private const ENV_KEYS = ['GUARDIAN_RECOVERY_TOKEN', 'VTINNOVATIONS_GUARDIAN_TOKEN'];
    private const TOKEN_FILE = 'access.token';

    public function __construct(
        private readonly WorkingDirectoryProviderInterface $workingDirectory,
    ) {
    }

    /**
     * @return 'env'|'file'|'missing'
     */
    public function source(): string
    {
        if ($this->envToken() !== null) {
            return 'env';
        }

        return is_file($this->tokenFile()) ? 'file' : 'missing';
    }

    public function exists(): bool
    {
        return $this->source() !== 'missing';
    }

    /**
     * Returns the active token, generating and storing one if none exists.
     */
    public function getActiveToken(): string
    {
        $env = $this->envToken();
        if ($env !== null) {
            return $env;
        }
        $file = $this->tokenFile();
        if (is_file($file)) {
            $content = trim((string) @file_get_contents($file));
            if ($content !== '') {
                return $content;
            }
        }

        return $this->generateAndStore();
    }

    /**
     * Generates and stores a fresh token, returning it. If the token is pinned
     * via an environment variable, that takes precedence and rotation of the
     * file has no effect until the env var is removed.
     */
    public function rotate(): string
    {
        return $this->generateAndStore();
    }

    public function isValid(?string $presented): bool
    {
        if ($presented === null || $presented === '') {
            return false;
        }
        $active = $this->getActiveToken();

        return $active !== '' && hash_equals($active, $presented);
    }

    private function generateAndStore(): string
    {
        $token = bin2hex(random_bytes(48));
        $file = $this->tokenFile();
        $dir = \dirname($file);
        if (!is_dir($dir) && !@mkdir($dir, 0o750, true) && !is_dir($dir)) {
            throw new GuardianException('Could not create the Guardian working directory for the recovery token.');
        }
        if (@file_put_contents($file, $token, \LOCK_EX) === false) {
            throw new GuardianException('Could not store the recovery token.');
        }
        @chmod($file, 0o600);

        return $token;
    }

    private function envToken(): ?string
    {
        foreach (self::ENV_KEYS as $key) {
            $value = getenv($key);
            if (\is_string($value) && trim($value) !== '') {
                return trim($value);
            }
            $server = $_SERVER[$key] ?? $_ENV[$key] ?? null;
            if (\is_string($server) && trim($server) !== '') {
                return trim($server);
            }
        }

        return null;
    }

    private function tokenFile(): string
    {
        return $this->workingDirectory->resolve(self::TOKEN_FILE);
    }
}
