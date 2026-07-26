<?php

declare(strict_types=1);

/*
 * This file is part of the "Guardian for TYPO3" extension for TYPO3 CMS.
 *
 * @author    V&T Innovations Team
 * @license   LGPL-3.0-or-later
 * @copyright V&T Innovations 2026 - 2028
 */

namespace Vtinnovations\GuardianTypo3\Infrastructure\Packagist;

use Vtinnovations\GuardianTypo3\Application\Contract\WorkingDirectoryProviderInterface;
use Vtinnovations\GuardianTypo3\Domain\Exception\GuardianException;
use Vtinnovations\GuardianTypo3\Infrastructure\Ter\TerHttpTransportInterface;

/**
 * Resolves real Composer package identities and version metadata for TYPO3
 * extensions from Packagist over FIXED trusted HTTPS endpoints:
 *
 *   - keyword search : GET https://packagist.org/search.json?q=…&type=typo3-cms-extension
 *   - package meta   : GET https://repo.packagist.org/p2/{vendor}/{package}.json
 *
 * Identities are NEVER invented — they come straight from Packagist. Results are
 * cached in the private, bounded TTL cache. Failures surface as precise codes.
 */
final class PackagistClient
{
    private const SEARCH_BASE = 'https://packagist.org/search.json';
    private const META_BASE = 'https://repo.packagist.org/p2/';
    private const CACHE_DIR = 'ter/packagist';
    private const CACHE_TTL = 3600;
    private const CACHE_MAX_FILES = 400;

    public function __construct(
        private readonly TerHttpTransportInterface $transport,
        private readonly WorkingDirectoryProviderInterface $workingDirectory,
    ) {
    }

    /**
     * Keyword search restricted to TYPO3 CMS extensions.
     *
     * @return list<array{name: string, description: string, repository: string, url: string}>
     * @throws GuardianException
     */
    public function search(string $query): array
    {
        $query = trim($query);
        if ($query === '') {
            return [];
        }
        $url = self::SEARCH_BASE . '?q=' . rawurlencode($query) . '&type=typo3-cms-extension&per_page=30';
        $data = $this->getJson($url);
        $results = $data['results'] ?? [];
        if (!\is_array($results)) {
            return [];
        }

        $out = [];
        foreach ($results as $row) {
            if (!\is_array($row) || !isset($row['name']) || !\is_string($row['name'])) {
                continue;
            }
            $out[] = [
                'name' => strtolower($row['name']),
                'description' => (string) ($row['description'] ?? ''),
                'repository' => (string) ($row['repository'] ?? ''),
                'url' => (string) ($row['url'] ?? ''),
            ];
        }

        return $out;
    }

    /**
     * The highest stable version's metadata for a Composer package, or null when
     * the package does not exist on Packagist (404).
     *
     * @return array{version: string, require: array<string, string>, time: ?string, abandoned: bool}|null
     * @throws GuardianException
     */
    public function latestVersion(string $composerName): ?array
    {
        if (preg_match('#^[a-z0-9]([_.-]?[a-z0-9]+)*/[a-z0-9](([_.]|-{1,2})?[a-z0-9]+)*$#', $composerName) !== 1) {
            return null;
        }
        $data = $this->getJson(self::META_BASE . $composerName . '.json', true);
        if ($data === null) {
            return null;
        }
        $versions = $data['packages'][$composerName] ?? null;
        if (!\is_array($versions) || $versions === []) {
            return null;
        }

        $best = null;
        $bestNumber = '';
        $abandoned = false;
        foreach ($versions as $v) {
            if (!\is_array($v) || !isset($v['version']) || !\is_string($v['version'])) {
                continue;
            }
            $number = ltrim($v['version'], 'v');
            // Skip dev / branch aliases for the "stable latest".
            if (preg_match('/^\d+\.\d+/', $number) !== 1 || stripos($number, 'dev') !== false) {
                continue;
            }
            if ($bestNumber === '' || version_compare($number, $bestNumber, '>')) {
                $best = $v;
                $bestNumber = $number;
            }
            if (isset($v['abandoned'])) {
                $abandoned = true;
            }
        }
        if ($best === null) {
            return null;
        }

        $require = [];
        foreach (($best['require'] ?? []) as $dep => $constraint) {
            if (\is_string($dep) && \is_string($constraint)) {
                $require[strtolower($dep)] = $constraint;
            }
        }

        return [
            'version' => $bestNumber,
            'require' => $require,
            'time' => \is_string($best['time'] ?? null) ? $best['time'] : null,
            'abandoned' => $abandoned,
        ];
    }

    /**
     * @return array<int|string, mixed>|null
     * @throws GuardianException
     */
    private function getJson(string $url, bool $nullOn404 = false): ?array
    {
        $cached = $this->readCache($url);
        if ($cached !== null) {
            return $cached;
        }
        $response = $this->transport->get($url);
        $status = $response['status'];
        if ($status === 404 && $nullOn404) {
            return null;
        }
        if ($status === 429) {
            throw new GuardianException('packagist_rate_limited');
        }
        if ($status < 200 || $status >= 300) {
            throw new GuardianException('packagist_http_error');
        }
        $decoded = json_decode($response['body'], true);
        if (!\is_array($decoded)) {
            throw new GuardianException('unsupported_schema');
        }
        $this->writeCache($url, $decoded);

        return $decoded;
    }

    /**
     * @return array<int|string, mixed>|null
     */
    private function readCache(string $url): ?array
    {
        $file = $this->cacheFile($url);
        if (!is_file($file) || (time() - (int) @filemtime($file)) > self::CACHE_TTL) {
            return null;
        }
        $data = json_decode((string) @file_get_contents($file), true);

        return \is_array($data) ? $data : null;
    }

    /**
     * @param array<int|string, mixed> $data
     */
    private function writeCache(string $url, array $data): void
    {
        $dir = $this->workingDirectory->resolve(self::CACHE_DIR);
        if (!is_dir($dir) && !@mkdir($dir, 0o750, true) && !is_dir($dir)) {
            return;
        }
        $files = glob($dir . '/*.json') ?: [];
        if (\count($files) >= self::CACHE_MAX_FILES) {
            usort($files, static fn (string $a, string $b): int => (int) @filemtime($a) <=> (int) @filemtime($b));
            foreach (\array_slice($files, 0, \count($files) - self::CACHE_MAX_FILES + 1) as $old) {
                @unlink($old);
            }
        }
        @file_put_contents($this->cacheFile($url), json_encode($data), \LOCK_EX);
    }

    private function cacheFile(string $url): string
    {
        return $this->workingDirectory->resolve(self::CACHE_DIR . '/' . sha1($url) . '.json');
    }
}
