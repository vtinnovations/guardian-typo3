<?php

declare(strict_types=1);

/*
 * This file is part of the "Guardian for TYPO3" extension for TYPO3 CMS.
 *
 * @author    V&T Innovations Team
 * @license   LGPL-3.0-or-later
 * @copyright V&T Innovations 2026 - 2028
 */

namespace Vtinnovations\GuardianTypo3\Domain\Exception;

/**
 * Thrown by foundations whose runtime behaviour is deliberately not available
 * yet in the current development phase.
 *
 * Guardian never returns a silent "success" from an unimplemented operation.
 * Anything not built yet must fail loudly and safely — this exception is the
 * single, greppable marker for that contract.
 */
final class NotImplementedException extends GuardianException
{
    public static function forFeature(string $feature): self
    {
        return new self(sprintf(
            'Guardian: "%s" is not implemented in this phase. It is intentionally '
            . 'unavailable and must not be treated as a no-op success.',
            $feature
        ));
    }
}
