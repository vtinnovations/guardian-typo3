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
 * Base class for all Guardian domain exceptions.
 *
 * Catching this type lets callers distinguish Guardian's own, well-defined
 * error conditions from unexpected framework or runtime errors.
 */
class GuardianException extends \RuntimeException
{
}
