<?php

declare(strict_types=1);

/*
 * This file is part of the "Guardian for TYPO3" extension for TYPO3 CMS.
 *
 * @author    V&T Innovations Team
 * @license   LGPL-3.0-or-later
 * @copyright V&T Innovations 2026 - 2028
 */

namespace Vtinnovations\GuardianTypo3\Domain\Environment;

/**
 * The host names this installation is configured to answer to.
 *
 * The list comes from the installation's own configuration, never from a request
 * header: a caller cannot add a name to it by asking, which is what makes it
 * usable as one half of an authorisation decision. Each entry is already a single
 * canonical host {@see HostIdentity}; nothing here interprets, widens or shortens
 * one.
 *
 * Order is the configuration's own and is preserved, because the first entry is
 * the installation's primary name — the one used when the host currently being
 * served is not itself a configured one, as happens where a backend is reached
 * under a name of its own. Sorting would replace a meaningful choice with an
 * alphabetical accident.
 *
 * Every comparison in this class is exact equality of canonical names. There is
 * deliberately no suffix, prefix, wildcard or registrable-domain matching, so no
 * caller can widen a binding through it.
 */
final class HostInventory
{
    /**
     * @param list<string> $hosts canonical, unique, in configuration order
     */
    private function __construct(
        public readonly array $hosts,
    ) {
    }

    /**
     * Builds the inventory from raw configured values, dropping anything that is
     * not a usable host and any repeat of one already present.
     *
     * @param iterable<mixed> $candidates
     */
    public static function of(iterable $candidates): self
    {
        $hosts = [];
        foreach ($candidates as $candidate) {
            if (!\is_string($candidate)) {
                continue;
            }
            $host = HostIdentity::normalize($candidate);
            if ($host !== '' && !\in_array($host, $hosts, true)) {
                $hosts[] = $host;
            }
        }

        return new self($hosts);
    }

    public static function empty(): self
    {
        return new self([]);
    }

    public function isEmpty(): bool
    {
        return $this->hosts === [];
    }

    /** Exact membership. An unusable candidate never matches. */
    public function contains(string $candidate): bool
    {
        $host = HostIdentity::normalize($candidate);
        if ($host === '') {
            return false;
        }
        foreach ($this->hosts as $configured) {
            if (hash_equals($configured, $host)) {
                return true;
            }
        }

        return false;
    }

    /** The installation's primary configured name, or '' when none is configured. */
    public function primary(): string
    {
        return $this->hosts[0] ?? '';
    }

    /**
     * The host to carry out an exchange for.
     *
     * The name currently being served is used when it is one of the configured
     * ones, so an administrator working on a particular site verifies that site.
     * Otherwise the primary configured name is used, which is what makes the
     * choice deterministic rather than dependent on which URL a backend happens to
     * be open under.
     */
    public function select(string $currentHost): string
    {
        return $this->contains($currentHost) ? HostIdentity::normalize($currentHost) : $this->primary();
    }

    /**
     * The one configured host that the vendor also authorised, or '' when there
     * is none.
     *
     * This is the intersection of two sets that are both out of a caller's reach:
     * what this installation is configured to be, and what the vendor signed. One
     * exact member of both is enough, and the host currently being served wins
     * when it qualifies so that background work and interactive work agree on the
     * same answer.
     *
     * @param list<string> $authorized
     */
    public function match(string $currentHost, array $authorized): string
    {
        $current = HostIdentity::normalize($currentHost);
        if ($current !== '' && $this->contains($current) && $this->isMember($current, $authorized)) {
            return $current;
        }
        foreach ($this->hosts as $configured) {
            if ($this->isMember($configured, $authorized)) {
                return $configured;
            }
        }

        return '';
    }

    /**
     * @param list<string> $authorized
     */
    private function isMember(string $host, array $authorized): bool
    {
        foreach ($authorized as $candidate) {
            if (\is_string($candidate) && $candidate !== '' && hash_equals($candidate, $host)) {
                return true;
            }
        }

        return false;
    }
}
