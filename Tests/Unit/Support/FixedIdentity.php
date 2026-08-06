<?php

declare(strict_types=1);

/*
 * This file is part of the "Guardian for TYPO3" extension for TYPO3 CMS.
 *
 * @author    V&T Innovations Team
 * @license   LGPL-3.0-or-later
 * @copyright V&T Innovations 2026 - 2028
 */

namespace Vtinnovations\GuardianTypo3\Tests\Unit\Support;

use Psr\Http\Message\ServerRequestInterface;
use Vtinnovations\GuardianTypo3\Application\Contract\InstallationIdentityInterface;

final class FixedIdentity implements InstallationIdentityInterface
{
    public function __construct(
        private string $host = 'example.com',
        private bool $live = true,
    ) {
    }

    public function current(): string
    {
        return $this->host;
    }

    public function isLive(): bool
    {
        return $this->live;
    }

    public function resolveFrom(ServerRequestInterface $request): string
    {
        return $this->host;
    }

    public function set(string $host, bool $live = true): void
    {
        $this->host = $host;
        $this->live = $live;
    }
}
