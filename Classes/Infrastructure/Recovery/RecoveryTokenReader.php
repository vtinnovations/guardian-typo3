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

/**
 * Read-only view of the standalone recovery-panel access token, ported from the
 * audited Contao PanelAuth but WITHOUT the ability to generate or rotate a token
 * (those are write operations reserved for the recovery-panel phase).
 *
 * It reports only where a token WOULD come from — the
 * VTINNOVATIONS_GUARDIAN_TOKEN environment variable (preferred) or the
 * auto-generated file — and never returns or creates a secret. Reading the
 * environment here is acceptable: this is a TYPO3-free infrastructure adapter,
 * not a domain service.
 */
final class RecoveryTokenReader
{
    private const ENV_KEY = 'VTINNOVATIONS_GUARDIAN_TOKEN';
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
        $env = getenv(self::ENV_KEY);
        if (\is_string($env) && trim($env) !== '') {
            return 'env';
        }

        return is_file($this->tokenFile()) ? 'file' : 'missing';
    }

    /**
     * Whether any token is currently configured (without revealing it).
     */
    public function exists(): bool
    {
        return $this->source() !== 'missing';
    }

    private function tokenFile(): string
    {
        return $this->workingDirectory->resolve(self::TOKEN_FILE);
    }
}
