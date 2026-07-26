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

use Vtinnovations\GuardianTypo3\Application\Contract\ProjectEnvironmentInterface;
use Vtinnovations\GuardianTypo3\Application\Update\PackageManager;
use Vtinnovations\GuardianTypo3\Domain\Exception\GuardianException;
use Vtinnovations\GuardianTypo3\Infrastructure\Packagist\PackagistClient;
use Vtinnovations\GuardianTypo3\Infrastructure\Ter\TerClient;

/**
 * Searches the TYPO3 Extension Repository (authoritative extension-key metadata)
 * and Packagist (keyword search + Composer package identities), then returns
 * Guardian-normalised result rows. The strategy fixes the "not found" bug:
 *
 *   1. Normalise the query to an extension key (content-blocks / "Content Blocks"
 *      → content_blocks) and try the REAL exact-key TER endpoint first.
 *   2. Run a Packagist keyword search restricted to typo3-cms-extension for
 *      broad matches and authoritative Composer identities.
 *   3. Enrich each Composer identity with its latest stable version + TYPO3/PHP
 *      constraints from Packagist metadata.
 *
 * A TER/Packagist 404 or an empty result set is a genuine "no match". A transport
 * or parsing failure (DNS / TLS / timeout / unreachable / unsupported schema) is
 * NEVER converted into "not found": it propagates as its precise code so the UI
 * can show a distinct, administrator-safe message.
 */
final class TerSearchService
{
    private const MAX_RESULTS = 40;
    private const ENRICH_LIMIT = 15;
    private const TRANSPORT_CODES = ['dns_failure', 'tls_failure', 'timeout', 'service_unreachable', 'transport_error', 'ter_http_error', 'packagist_http_error', 'ter_rate_limited', 'packagist_rate_limited', 'unsupported_schema', 'untrusted_endpoint'];

    public function __construct(
        private readonly TerClient $ter,
        private readonly PackagistClient $packagist,
        private readonly TerExtensionMapper $mapper,
        private readonly PackageManager $packages,
        private readonly ProjectEnvironmentInterface $environment,
    ) {
    }

    /**
     * @return array{query: string, count: int, results: list<array<string, mixed>>, source: string, degraded: bool}
     * @throws GuardianException precise transport/schema code when nothing could be retrieved
     */
    public function search(string $query): array
    {
        $query = trim($query);
        if ($query === '' || \strlen($query) > 100 || preg_match('/^[a-zA-Z0-9 ._-]+$/', $query) !== 1) {
            throw new GuardianException('ter_invalid_query');
        }

        $typo3Major = $this->currentTypo3Major();
        $php = $this->environment->phpVersion();
        $installed = fn (string $name): bool => $this->packages->isInstalled($name);

        /** @var array<string, array<string, mixed>> $byKey keyed by extension key */
        $byKey = [];
        $order = [];
        $transportError = null;
        $usedTer = false;
        $usedPackagist = false;

        // 1 — exact TER extension-key lookup (the real /api/v1/extension/{key}).
        $key = $this->normaliseKey($query);
        if ($key !== null) {
            try {
                $raw = $this->ter->extensionOrNull($key);
                $usedTer = true;
                if ($raw !== null) {
                    $row = $this->mapper->map($raw, $typo3Major, $php, $installed);
                    $rowKey = (string) ($row['extension_key'] ?: $key);
                    $byKey[$rowKey] = $this->finaliseRow($row);
                    $order[] = $rowKey;
                }
            } catch (GuardianException $e) {
                $transportError = $this->recordTransport($transportError, $e->getMessage());
            }
        }

        // 2 — Packagist keyword search (authoritative Composer identities).
        try {
            $hits = $this->packagist->search($query);
            $usedPackagist = true;
            $enriched = 0;
            foreach ($hits as $hit) {
                $rowKey = $this->keyFromComposerName($hit['name']);
                if ($rowKey === '') {
                    continue;
                }
                if (isset($byKey[$rowKey])) {
                    // A TER row already exists for this key. If it lacks a Composer
                    // identity, adopt the Packagist one and RE-DERIVE the state so
                    // the row becomes installable when its (already computed) TYPO3
                    // /PHP compatibility allows it — the identity fix must not be
                    // lost by a stale auto_installable/reason.
                    if (($byKey[$rowKey]['composer_name'] ?? null) === null) {
                        $byKey[$rowKey]['composer_name'] = $hit['name'];
                        $byKey[$rowKey] = $this->finaliseRow($this->mapper->deriveState($byKey[$rowKey], $installed));
                    }
                    continue;
                }
                $meta = null;
                if ($enriched < self::ENRICH_LIMIT) {
                    try {
                        $meta = $this->packagist->latestVersion($hit['name']);
                        $enriched++;
                    } catch (GuardianException) {
                        $meta = null; // enrichment is best-effort; identity still stands
                    }
                }
                $raw = $this->rawFromPackagist($hit, $meta);
                $row = $this->finaliseRow($this->mapper->map($raw, $typo3Major, $php, $installed));
                if (!isset($byKey[$rowKey])) {
                    $order[] = $rowKey;
                }
                $byKey[$rowKey] = $row;
            }
        } catch (GuardianException $e) {
            $transportError = $this->recordTransport($transportError, $e->getMessage());
        }

        $results = [];
        foreach ($order as $k) {
            if (isset($byKey[$k])) {
                $results[] = $byKey[$k];
            }
            if (\count($results) >= self::MAX_RESULTS) {
                break;
            }
        }

        // A transport failure must NOT read as "no match": only throw when we
        // could not retrieve ANY data at all.
        if ($results === [] && $transportError !== null) {
            throw new GuardianException($transportError);
        }

        $source = $usedTer && $usedPackagist ? 'ter+packagist' : ($usedTer ? 'ter' : ($usedPackagist ? 'packagist' : 'none'));

        return [
            'query' => $query,
            'count' => \count($results),
            'results' => $results,
            'source' => $source,
            'degraded' => $transportError !== null,
        ];
    }

    /**
     * Full analysis of a single extension by key (used before an install) — TER
     * first, Packagist to resolve/confirm the Composer identity.
     *
     * @return array<string, mixed>
     * @throws GuardianException
     */
    public function analyse(string $extensionKey): array
    {
        $key = $this->normaliseKey($extensionKey);
        if ($key === null) {
            throw new GuardianException('ter_invalid_extension_key');
        }
        $typo3Major = $this->currentTypo3Major();
        $php = $this->environment->phpVersion();
        $installed = fn (string $name): bool => $this->packages->isInstalled($name);

        $raw = $this->ter->extensionOrNull($key);
        if ($raw !== null) {
            $row = $this->mapper->map($raw, $typo3Major, $php, $installed);
            if (($row['composer_name'] ?? null) === null) {
                $match = $this->packagistIdentityFor($key);
                if ($match !== null) {
                    $row['composer_name'] = $match;
                    $row = $this->mapper->deriveState($row, $installed);
                }
            }

            return $this->finaliseRow($row);
        }

        // Not in TER by key → resolve purely from Packagist.
        foreach ($this->packagist->search($extensionKey) as $hit) {
            if ($this->keyFromComposerName($hit['name']) === $key) {
                $meta = $this->packagist->latestVersion($hit['name']);

                return $this->finaliseRow($this->mapper->map($this->rawFromPackagist($hit, $meta), $typo3Major, $php, $installed));
            }
        }

        throw new GuardianException('ter_not_found');
    }

    private function packagistIdentityFor(string $key): ?string
    {
        try {
            foreach ($this->packagist->search($key) as $hit) {
                if ($this->keyFromComposerName($hit['name']) === $key) {
                    return $hit['name'];
                }
            }
        } catch (GuardianException) {
            // best-effort
        }

        return null;
    }

    /**
     * @param array<string, mixed> $row
     * @return array<string, mixed>
     */
    private function finaliseRow(array $row): array
    {
        $row['latest_overall'] = $row['latest_overall'] ?? ($row['latest_version'] ?? '');

        return $row;
    }

    /**
     * @param array{name: string, description: string} $hit
     * @param array{version: string, require: array<string, string>, time: ?string, abandoned: bool}|null $meta
     * @return array<string, mixed>
     */
    private function rawFromPackagist(array $hit, ?array $meta): array
    {
        $key = $this->keyFromComposerName($hit['name']);
        $current = [
            'number' => $meta['version'] ?? '',
            'composer_name' => $hit['name'],
            'typo3_versions' => $meta !== null ? $this->typo3MajorsFromConstraint($meta['require']['typo3/cms-core'] ?? '') : [],
            'php_version' => $meta['require']['php'] ?? null,
            'upload_date' => $meta['time'] ?? null,
            'abandoned' => $meta['abandoned'] ?? false,
        ];

        return [
            'key' => $key,
            'title' => $this->humanise($key),
            'description' => $hit['description'],
            'current_version' => $current,
        ];
    }

    /**
     * Normalise a search term to a valid TYPO3 extension key WITHOUT changing a
     * real key: "content-blocks" / "Content Blocks" / "CONTENT_BLOCKS" →
     * "content_blocks". Returns null when no plausible key can be formed.
     */
    private function normaliseKey(string $query): ?string
    {
        $key = strtolower(trim($query));
        $key = preg_replace('/[\s.\-]+/', '_', $key) ?? '';
        $key = preg_replace('/[^a-z0-9_]/', '', $key) ?? '';
        $key = trim(preg_replace('/_+/', '_', $key) ?? '', '_');

        return preg_match('/^[a-z0-9][a-z0-9_]{1,59}$/', $key) === 1 ? $key : null;
    }

    private function keyFromComposerName(string $name): string
    {
        $base = str_contains($name, '/') ? substr($name, (int) strrpos($name, '/') + 1) : $name;
        $key = strtolower(preg_replace('/[^a-z0-9]+/i', '_', $base) ?? '');

        return trim(preg_replace('/_+/', '_', $key) ?? '', '_');
    }

    private function humanise(string $key): string
    {
        return ucwords(str_replace('_', ' ', $key));
    }

    /**
     * @return list<int>
     */
    private function typo3MajorsFromConstraint(string $constraint): array
    {
        if ($constraint === '') {
            return [];
        }
        $majors = [];
        if (preg_match_all('/(\d+)(?:\.\d+)?/', $constraint, $m) !== false) {
            foreach ($m[1] as $major) {
                $n = (int) $major;
                if ($n >= 6 && $n <= 20) {
                    $majors[] = $n;
                }
            }
        }

        return array_values(array_unique($majors));
    }

    private function recordTransport(?string $current, string $code): ?string
    {
        if (!\in_array($code, self::TRANSPORT_CODES, true)) {
            return $current; // invalid key / not-a-transport → ignore for this source
        }

        // Keep the first (most specific) transport error we saw.
        return $current ?? $code;
    }

    private function currentTypo3Major(): int
    {
        return (int) explode('.', ltrim($this->environment->typo3Version(), 'v'))[0];
    }
}
