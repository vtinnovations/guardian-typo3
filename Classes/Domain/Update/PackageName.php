<?php

declare(strict_types=1);

/*
 * This file is part of the "Guardian for TYPO3" extension for TYPO3 CMS.
 *
 * @author    V&T Innovations Team
 * @license   LGPL-3.0-or-later
 * @copyright V&T Innovations 2026 - 2028
 */

namespace Vtinnovations\GuardianTypo3\Domain\Update;

use Vtinnovations\GuardianTypo3\Domain\Exception\GuardianException;

/**
 * Pure validation of a Composer package name for selective updates.
 *
 * The single most important security property of the selective-update path is
 * that a browser-supplied package name can NEVER be turned into a Composer flag
 * or shell fragment. This value object enforces Composer's own package-name
 * syntax (`vendor/name`, lowercase, no leading dash, no spaces) so a validated
 * name can be passed as its own argv element with zero risk of injection.
 *
 * @see https://getcomposer.org/doc/04-schema.md#name
 */
final class PackageName
{
    /**
     * Composer's canonical name pattern. Vendor and package segments each start
     * with an alphanumeric and may contain [a-z0-9_.-]. Crucially there is no
     * leading "-", so a value can never be mistaken for a CLI option.
     */
    private const PATTERN = '#^[a-z0-9]([_.-]?[a-z0-9]+)*/[a-z0-9](([_.]|-{1,2})?[a-z0-9]+)*$#';

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
            throw new GuardianException('A package name must not be empty.');
        }
        if (\strlen($name) > 150) {
            throw new GuardianException('The package name is too long to be valid.');
        }
        if (str_contains($name, "\0")) {
            throw new GuardianException('The package name must not contain null bytes.');
        }
        if (str_starts_with($name, '-')) {
            throw new GuardianException('The package name must not start with a dash.');
        }
        if (preg_match(self::PATTERN, $name) !== 1) {
            throw new GuardianException(sprintf('"%s" is not a valid Composer package name (expected vendor/name).', $name));
        }

        return new self($name);
    }

    /**
     * Validates a list, returning the validated names. Throws on the first
     * invalid entry so a bad selection never partially executes.
     *
     * @param list<mixed> $names
     * @return list<string>
     * @throws GuardianException
     */
    public static function validateList(array $names): array
    {
        $valid = [];
        foreach ($names as $name) {
            if (!\is_string($name)) {
                throw new GuardianException('Package names must be strings.');
            }
            $valid[] = self::fromString($name)->value;
        }

        return array_values(array_unique($valid));
    }
}
