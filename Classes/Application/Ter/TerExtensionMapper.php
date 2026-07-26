<?php

declare(strict_types=1);

/*
 * This file is part of the "Guardian for TYPO3" extension for TYPO3 CMS.
 *
 * @author    V&T Innovations Team
 * @license   LGPL-3.0-or-later
 * @copyright V&T Innovations 2026 - 2028
 */

namespace Vtinnovations\GuardianTypo3\Application\Ter;

use Vtinnovations\GuardianTypo3\Domain\Update\PackageName;

/**
 * Normalises a raw TER API record (search item OR extension detail) into a
 * stable, defensively-parsed shape and decides — SERVER-SIDE — TYPO3/PHP
 * compatibility, Composer identity availability, installed state and whether
 * Guardian can install it automatically (with a precise machine reason when not).
 *
 * It never trusts a single field name blindly: the TER payload shape has varied
 * across API revisions, so each attribute is read from a small set of candidate
 * keys and validated. No result is ever hard-coded.
 */
final class TerExtensionMapper
{
    /**
     * @param array<string, mixed> $raw
     * @param int $currentTypo3Major installed TYPO3 major (e.g. 13)
     * @param callable(string):bool $isInstalled tests a composer package name
     * @return array<string, mixed>
     */
    public function map(array $raw, int $currentTypo3Major, string $currentPhpVersion, callable $isInstalled): array
    {
        $key = $this->firstString($raw, ['key', 'extension_key', 'extensionKey']);
        $name = $this->firstString($raw, ['title', 'name']) ?? $key;
        $current = $this->currentVersion($raw);

        $version = $this->firstString($current, ['number', 'version', 'latest_version']) ?? '';
        $composerName = $this->composerName($current, $raw);
        $typo3Majors = $this->typo3Majors($current, $raw);
        $phpConstraint = $this->firstString($current, ['php_version', 'php_constraint', 'phpVersions']);
        $author = $this->author($raw, $current);
        $description = $this->firstString($raw, ['description', 'abstract']) ?? $this->firstString($current, ['description']) ?? '';
        $lastUpdated = $this->firstString($current, ['upload_date', 'last_updated', 'uploadDate'])
            ?? $this->firstString($raw, ['last_updated', 'meta_last_updated']);

        $abandoned = $this->flag($raw, ['abandoned', 'is_abandoned']) || $this->flag($current, ['abandoned']);
        $deprecated = $this->flag($raw, ['deprecated', 'is_deprecated']) || $this->flag($current, ['deprecated']);

        $typo3Compatible = $typo3Majors === [] ? null : \in_array($currentTypo3Major, $typo3Majors, true);
        $phpCompatible = $this->phpCompatible($phpConstraint, $currentPhpVersion);

        $row = [
            'name' => $name,
            'extension_key' => $key,
            'latest_version' => $version,
            'typo3_versions' => $typo3Majors,
            'php_constraint' => $phpConstraint,
            'author' => $author,
            'description' => $description,
            'last_updated' => $lastUpdated,
            'abandoned' => $abandoned,
            'deprecated' => $deprecated,
            'composer_name' => $composerName,
            'typo3_compatible' => $typo3Compatible,
            'php_compatible' => $phpCompatible,
        ];

        // Identity and compatibility are DISTINCT dimensions and are derived
        // together only at the very end, so a compatibility failure never blanks
        // the Composer identity (and vice-versa).
        return $this->deriveState($row, $isInstalled);
    }

    /**
     * (Re)compute the separate identity + compatibility states and the single
     * install decision from a row's dimensions. Public so the search service can
     * re-derive after resolving a Composer identity from Packagist WITHOUT losing
     * the already-computed TYPO3/PHP compatibility.
     *
     * @param array<string, mixed> $row must carry composer_name, typo3_compatible, php_compatible
     * @param callable(string):bool $isInstalled
     * @return array<string, mixed>
     */
    public function deriveState(array $row, callable $isInstalled): array
    {
        $composerName = \is_string($row['composer_name'] ?? null) && $row['composer_name'] !== '' ? $row['composer_name'] : null;
        $composerAvailable = $composerName !== null;
        $installed = $composerAvailable && $isInstalled($composerName);
        $typo3Compatible = $row['typo3_compatible'] ?? null;
        $phpCompatible = $row['php_compatible'] ?? null;

        // Compatibility state — independent of whether an identity exists.
        if ($typo3Compatible === false) {
            $compatibilityState = 'typo3_incompatible';
        } elseif ($phpCompatible === false) {
            $compatibilityState = 'php_incompatible';
        } elseif ($typo3Compatible === null && $phpCompatible === null) {
            $compatibilityState = 'metadata_unavailable';
        } else {
            $compatibilityState = 'installable';
        }

        $composerState = $composerAvailable ? 'composer_identity_available' : 'composer_identity_missing';
        // Installable only when we have an identity AND nothing blocks it. Unknown
        // compatibility (null) is not a blocker — Composer resolves it at dry run.
        $auto = $composerAvailable && !$installed && $typo3Compatible !== false && $phpCompatible !== false;

        $reason = null;
        if ($installed) {
            $reason = 'already_installed';
        } elseif (!$composerAvailable) {
            $reason = 'composer_identity_missing';
        } elseif ($typo3Compatible === false) {
            $reason = 'typo3_incompatible';
        } elseif ($phpCompatible === false) {
            $reason = 'php_incompatible';
        }

        $row['composer_name'] = $composerName;
        $row['composer_available'] = $composerAvailable;
        $row['composer_state'] = $composerState;
        $row['compatibility_state'] = $compatibilityState;
        $row['already_installed'] = $installed;
        $row['auto_installable'] = $auto;
        $row['reason'] = $reason;

        return $row;
    }

    /**
     * @param array<string, mixed> $raw
     * @return array<string, mixed>
     */
    private function currentVersion(array $raw): array
    {
        foreach (['current_version', 'currentVersion', 'latest_version'] as $k) {
            if (isset($raw[$k]) && \is_array($raw[$k])) {
                return $raw[$k];
            }
        }
        // Some payloads embed versions as a list; take the highest by number.
        $versions = $raw['versions'] ?? null;
        if (\is_array($versions) && $versions !== []) {
            $best = null;
            $bestNumber = '';
            foreach ($versions as $v) {
                if (\is_array($v)) {
                    $num = $this->firstString($v, ['number', 'version']) ?? '';
                    if ($num !== '' && ($bestNumber === '' || version_compare($num, $bestNumber, '>'))) {
                        $best = $v;
                        $bestNumber = $num;
                    }
                }
            }
            if ($best !== null) {
                return $best;
            }
        }

        return $raw;
    }

    /**
     * @param array<string, mixed> $current
     * @param array<string, mixed> $raw
     */
    private function composerName(array $current, array $raw): ?string
    {
        $candidate = $this->firstString($current, ['composer_name', 'composerName'])
            ?? $this->firstString($raw, ['composer_name', 'composerName']);
        if ($candidate === null) {
            return null;
        }

        return PackageName::isValid($candidate) ? strtolower($candidate) : null;
    }

    /**
     * @param array<string, mixed> $current
     * @param array<string, mixed> $raw
     * @return list<int>
     */
    private function typo3Majors(array $current, array $raw): array
    {
        $source = null;
        foreach ([$current, $raw] as $set) {
            foreach (['typo3_versions', 'typo3Versions', 'typo3'] as $k) {
                if (isset($set[$k]) && \is_array($set[$k])) {
                    $source = $set[$k];
                    break 2;
                }
            }
        }
        $out = [];
        foreach (\is_array($source) ? $source : [] as $entry) {
            if (\is_int($entry)) {
                $out[] = $entry;
            } elseif (\is_string($entry) && preg_match('/(\d+)/', $entry, $m) === 1) {
                $out[] = (int) $m[1];
            } elseif (\is_array($entry)) {
                $num = $this->firstString($entry, ['version', 'number', 'major']);
                if ($num !== null && preg_match('/(\d+)/', $num, $m) === 1) {
                    $out[] = (int) $m[1];
                }
            }
        }

        return array_values(array_unique(array_filter($out, static fn (int $n): bool => $n >= 6 && $n <= 20)));
    }

    private function phpCompatible(?string $constraint, string $currentPhpVersion): ?bool
    {
        if ($constraint === null || $constraint === '') {
            return null; // unknown → not a blocker
        }
        // Extract a minimum "X.Y" from a constraint like ">=8.1" or "8.1 - 8.3".
        if (preg_match('/(\d+\.\d+)/', $constraint, $m) === 1) {
            $min = $m[1];
            if (preg_match('/(\d+\.\d+)/', $currentPhpVersion, $c) === 1) {
                return version_compare($c[1], $min, '>=');
            }
        }

        return null;
    }

    /**
     * @param array<string, mixed> $raw
     * @param array<string, mixed> $current
     */
    private function author(array $raw, array $current): string
    {
        foreach ([$raw, $current] as $set) {
            if (isset($set['author'])) {
                if (\is_string($set['author'])) {
                    return $set['author'];
                }
                if (\is_array($set['author'])) {
                    $n = $this->firstString($set['author'], ['name', 'username', 'company']);
                    if ($n !== null) {
                        return $n;
                    }
                }
            }
        }

        return '';
    }

    /**
     * @param array<string, mixed> $data
     * @param list<string> $keys
     */
    private function firstString(array $data, array $keys): ?string
    {
        foreach ($keys as $k) {
            if (isset($data[$k]) && \is_string($data[$k]) && $data[$k] !== '') {
                return $data[$k];
            }
            if (isset($data[$k]) && (\is_int($data[$k]) || \is_float($data[$k]))) {
                return (string) $data[$k];
            }
        }

        return null;
    }

    /**
     * @param array<string, mixed> $data
     * @param list<string> $keys
     */
    private function flag(array $data, array $keys): bool
    {
        foreach ($keys as $k) {
            if (!empty($data[$k])) {
                return true;
            }
        }

        return false;
    }
}
