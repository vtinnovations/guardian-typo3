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
 * A validated `composer require` argument: a package name and an OPTIONAL version
 * constraint, joined as `vendor/name` or `vendor/name:constraint`.
 *
 * The name half is validated with the same strict {@see PackageName} syntax so it
 * can never become a CLI flag. The constraint half is restricted to the small,
 * safe character set Composer constraints actually use (`^~<>=!|* .,0-9a-zA-Z-`
 * plus spaces and the "@stability" marker) — no shell metacharacters, no leading
 * dash on the whole token — so the combined value is always a single safe argv
 * element with zero injection surface.
 */
final class PackageRequirement
{
    private const CONSTRAINT_PATTERN = '#^[\^~<>=!|*@a-zA-Z0-9.,\- ]+$#';

    private function __construct(
        public readonly string $name,
        public readonly ?string $constraint,
    ) {
    }

    /**
     * @throws GuardianException with a precise, non-sensitive reason
     */
    public static function fromString(string $value): self
    {
        $value = trim($value);
        if ($value === '') {
            throw new GuardianException('A package requirement must not be empty.');
        }
        if (str_contains($value, "\0")) {
            throw new GuardianException('The package requirement must not contain null bytes.');
        }
        if (\strlen($value) > 200) {
            throw new GuardianException('The package requirement is too long to be valid.');
        }

        $name = $value;
        $constraint = null;
        // Split on the FIRST ":" only — constraints never contain a colon.
        $colon = strpos($value, ':');
        if ($colon !== false) {
            $name = substr($value, 0, $colon);
            $constraint = trim(substr($value, $colon + 1));
        }

        $validName = PackageName::fromString($name)->value;

        if ($constraint !== null) {
            if ($constraint === '' || preg_match(self::CONSTRAINT_PATTERN, $constraint) !== 1) {
                throw new GuardianException(sprintf('"%s" is not a valid, safe Composer version constraint.', $constraint));
            }
        }

        return new self($validName, $constraint);
    }

    /**
     * The single argv element to hand to `composer require`.
     */
    public function toArgument(): string
    {
        return $this->constraint === null ? $this->name : $this->name . ':' . $this->constraint;
    }

    /**
     * @param list<mixed> $values
     * @return list<string> validated argv elements
     * @throws GuardianException on the first invalid entry
     */
    public static function validateList(array $values): array
    {
        $out = [];
        foreach ($values as $value) {
            if (!\is_string($value)) {
                throw new GuardianException('A package requirement must be a string.');
            }
            $out[] = self::fromString($value)->toArgument();
        }

        return array_values($out);
    }
}
