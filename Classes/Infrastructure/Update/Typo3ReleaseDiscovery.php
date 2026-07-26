<?php

declare(strict_types=1);

/*
 * This file is part of the "Guardian for TYPO3" extension for TYPO3 CMS.
 *
 * @author    V&T Innovations Team
 * @license   LGPL-3.0-or-later
 * @copyright V&T Innovations 2026 - 2028
 */

namespace Vtinnovations\GuardianTypo3\Infrastructure\Update;

use TYPO3\CMS\Core\Http\RequestFactory;
use Vtinnovations\GuardianTypo3\Application\Contract\ProjectEnvironmentInterface;

/**
 * Discovers available stable TYPO3 releases over direct, fixed HTTPS sources —
 * WITHOUT invoking the Composer CLI (which fails on many hosts: no exec, no
 * outbound Packagist auth, restricted PATH). The installed version is read from
 * local Composer metadata only, so it never needs the network.
 *
 * Sources (fixed, trusted, tried in order):
 *   1. official TYPO3 release API — GET https://get.typo3.org/api/v1/release/
 *      → a JSON array of { "version": "13.4.9", "type": "regular", ... }.
 *   2. fallback: Packagist metadata — GET https://repo.packagist.org/p2/typo3/cms-core.json
 *      → { "packages": { "typo3/cms-core": [ { "version": "v13.4.9", ... }, ... ] } }.
 *
 * Each source is parsed against its ACTUAL schema (no blind recursion). Only
 * strict semantic-version stables `X.Y.Z` (optionally `vX.Y.Z` or a
 * composer-normalized `X.Y.Z.0`) are kept; dev branches, aliases, alpha/beta/RC
 * and malformed strings are rejected. TLS peer + host verification is on,
 * redirects are refused, timeouts are bounded and the response body is size-capped.
 */
final class Typo3ReleaseDiscovery
{
    private const OFFICIAL_URL = 'https://get.typo3.org/api/v1/release/';
    private const PACKAGIST_URL = 'https://repo.packagist.org/p2/typo3/cms-core.json';
    private const MAX_BODY_BYTES = 6 * 1024 * 1024; // hard cap on downloaded metadata
    private const CORE_PACKAGE = 'typo3/cms-core';

    public function __construct(
        private readonly RequestFactory $requestFactory,
        private readonly ProjectEnvironmentInterface $environment,
    ) {
    }

    /**
     * @return array{
     *   success: bool,
     *   installedVersion: ?string,
     *   source: ?string,
     *   currentMajor: int,
     *   latestCurrentMajor: array{available: bool, version: ?string, upgradeType: string},
     *   nextMajor: array{available: bool, version: ?string, upgradeType: string},
     *   upgradePaths: list<array{targetVersion: string, upgradeType: string}>,
     *   errorCode: ?string,
     *   diagnostics: array<string, string|int>
     * }
     */
    public function discover(): array
    {
        $installed = $this->installedVersion();
        if ($installed === null) {
            return $this->failure(null, 'installed_version_unavailable', ['installed' => 'unresolved']);
        }

        $diagnostics = [];
        $versions = [];
        $source = null;

        foreach (['typo3_api' => self::OFFICIAL_URL, 'packagist' => self::PACKAGIST_URL] as $name => $url) {
            $body = $this->fetch($url, $diagnostics, $name);
            if ($body === null) {
                continue;
            }
            $data = json_decode($body, true);
            if (!\is_array($data)) {
                $diagnostics[$name] = 'invalid_json';
                continue;
            }
            $parsed = $name === 'typo3_api' ? $this->parseOfficial($data) : $this->parsePackagist($data);
            if ($parsed !== []) {
                $versions = $parsed;
                $source = $name;
                break;
            }
            $diagnostics[$name] = 'no_stable_versions';
        }

        if ($versions === []) {
            return $this->failure($installed, 'release_versions_unavailable', $diagnostics);
        }

        $installedMajor = (int) explode('.', $installed)[0];

        $currentMajorNewer = array_values(array_filter(
            $versions,
            static fn (string $v): bool => (int) explode('.', $v)[0] === $installedMajor && version_compare($v, $installed, '>')
        ));
        $nextMajor = array_values(array_filter(
            $versions,
            static fn (string $v): bool => (int) explode('.', $v)[0] === $installedMajor + 1
        ));

        $latestCurrent = $this->highest($currentMajorNewer);
        $latestNext = $this->highest($nextMajor);

        $paths = [];
        if ($latestCurrent !== null) {
            $paths[] = ['targetVersion' => $latestCurrent, 'upgradeType' => 'minor'];
        }
        if ($latestNext !== null) {
            $paths[] = ['targetVersion' => $latestNext, 'upgradeType' => 'major'];
        }

        return [
            'success' => true,
            'installedVersion' => $installed,
            'source' => $source,
            'currentMajor' => $installedMajor,
            'latestCurrentMajor' => ['available' => $latestCurrent !== null, 'version' => $latestCurrent, 'upgradeType' => 'minor'],
            'nextMajor' => ['available' => $latestNext !== null, 'version' => $latestNext, 'upgradeType' => 'major'],
            'upgradePaths' => $paths,
            'errorCode' => null,
            'diagnostics' => $diagnostics,
        ];
    }

    /**
     * Installed TYPO3 version from local Composer metadata — no network call.
     * Prefers the runtime API, falls back to the on-disk installed.php/json.
     */
    public function installedVersion(): ?string
    {
        if (class_exists(\Composer\InstalledVersions::class)) {
            try {
                if (\Composer\InstalledVersions::isInstalled(self::CORE_PACKAGE)) {
                    $pretty = \Composer\InstalledVersions::getPrettyVersion(self::CORE_PACKAGE);
                    $normalized = $pretty !== null ? $this->normalizeStable($pretty) : null;
                    if ($normalized !== null) {
                        return $normalized;
                    }
                }
            } catch (\Throwable) {
                // fall through to the on-disk fallback
            }
        }

        $project = rtrim($this->environment->projectPath(), '/');

        $installedPhp = $project . '/vendor/composer/installed.php';
        if (is_file($installedPhp)) {
            $data = @include $installedPhp;
            $pretty = \is_array($data) ? ($data['versions'][self::CORE_PACKAGE]['pretty_version'] ?? null) : null;
            if (\is_string($pretty)) {
                $normalized = $this->normalizeStable($pretty);
                if ($normalized !== null) {
                    return $normalized;
                }
            }
        }

        $installedJson = $project . '/vendor/composer/installed.json';
        if (is_file($installedJson)) {
            $decoded = json_decode((string) @file_get_contents($installedJson), true);
            $packages = \is_array($decoded) ? ($decoded['packages'] ?? $decoded) : [];
            if (\is_array($packages)) {
                foreach ($packages as $package) {
                    if (\is_array($package) && ($package['name'] ?? '') === self::CORE_PACKAGE) {
                        $normalized = $this->normalizeStable((string) ($package['version'] ?? ''));
                        if ($normalized !== null) {
                            return $normalized;
                        }
                    }
                }
            }
        }

        return null;
    }

    /**
     * @param array<string, string|int> $diagnostics
     */
    private function fetch(string $url, array &$diagnostics, string $name): ?string
    {
        try {
            $response = $this->requestFactory->request($url, 'GET', [
                'headers' => ['Accept' => 'application/json', 'User-Agent' => 'Guardian-TYPO3'],
                'allow_redirects' => false,
                'connect_timeout' => 4,
                'timeout' => 8,
                'verify' => true,           // strict TLS peer + host verification
                'http_errors' => false,
            ]);
        } catch (\Throwable $e) {
            $diagnostics[$name] = $this->safeReason($e);

            return null;
        }

        $status = $response->getStatusCode();
        $diagnostics[$name] = $status;
        if ($status < 200 || $status >= 300) {
            return null;
        }

        $body = $response->getBody();
        $content = '';
        try {
            while (!$body->eof() && \strlen($content) < self::MAX_BODY_BYTES) {
                $chunk = $body->read(65536);
                if ($chunk === '') {
                    break;
                }
                $content .= $chunk;
            }
        } catch (\Throwable $e) {
            $diagnostics[$name] = $this->safeReason($e);

            return null;
        }

        return $content !== '' ? $content : null;
    }

    /**
     * Official API: a flat array of release objects with a "version" field.
     *
     * @param array<int|string, mixed> $data
     * @return list<string>
     */
    private function parseOfficial(array $data): array
    {
        $out = [];
        foreach ($data as $release) {
            if (\is_array($release) && isset($release['version']) && \is_string($release['version'])) {
                $normalized = $this->normalizeStable($release['version']);
                if ($normalized !== null) {
                    $out[] = $normalized;
                }
            }
        }

        return $this->unique($out);
    }

    /**
     * Packagist p2: packages[typo3/cms-core] is an array of version objects.
     *
     * @param array<string, mixed> $data
     * @return list<string>
     */
    private function parsePackagist(array $data): array
    {
        $list = $data['packages'][self::CORE_PACKAGE] ?? null;
        if (!\is_array($list)) {
            return [];
        }
        $out = [];
        foreach ($list as $release) {
            if (\is_array($release) && isset($release['version']) && \is_string($release['version'])) {
                $normalized = $this->normalizeStable($release['version']);
                if ($normalized !== null) {
                    $out[] = $normalized;
                }
            }
        }

        return $this->unique($out);
    }

    /**
     * Accepts vX.Y.Z / X.Y.Z / composer-normalized X.Y.Z.0 → "X.Y.Z".
     * Rejects dev branches, aliases, alpha/beta/RC and anything malformed.
     */
    private function normalizeStable(string $version): ?string
    {
        $version = trim($version);
        if ($version !== '' && ($version[0] === 'v' || $version[0] === 'V')) {
            $version = substr($version, 1);
        }
        if (preg_match('/^(\d+)\.(\d+)\.(\d+)(?:\.\d+)?$/', $version, $m) !== 1) {
            return null;
        }

        return $m[1] . '.' . $m[2] . '.' . $m[3];
    }

    /**
     * @param list<string> $versions
     * @return list<string>
     */
    private function unique(array $versions): array
    {
        return array_values(array_unique($versions));
    }

    /**
     * @param list<string> $versions
     */
    private function highest(array $versions): ?string
    {
        if ($versions === []) {
            return null;
        }
        usort($versions, static fn (string $a, string $b): int => version_compare($a, $b));

        return end($versions) ?: null;
    }

    private function safeReason(\Throwable $e): string
    {
        // Never expose credentials/URLs; the exception class name is enough.
        $parts = explode('\\', $e::class);

        return (string) end($parts);
    }

    /**
     * @param array<string, string|int> $diagnostics
     * @return array{success: bool, installedVersion: ?string, source: ?string, currentMajor: int, latestCurrentMajor: array{available: bool, version: ?string, upgradeType: string}, nextMajor: array{available: bool, version: ?string, upgradeType: string}, upgradePaths: list<array{targetVersion: string, upgradeType: string}>, errorCode: ?string, diagnostics: array<string, string|int>}
     */
    private function failure(?string $installed, string $errorCode, array $diagnostics): array
    {
        $major = $installed !== null ? (int) explode('.', $installed)[0] : 0;

        return [
            'success' => false,
            'installedVersion' => $installed,
            'source' => null,
            'currentMajor' => $major,
            'latestCurrentMajor' => ['available' => false, 'version' => null, 'upgradeType' => 'minor'],
            'nextMajor' => ['available' => false, 'version' => null, 'upgradeType' => 'major'],
            'upgradePaths' => [],
            'errorCode' => $errorCode,
            'diagnostics' => $diagnostics,
        ];
    }
}
