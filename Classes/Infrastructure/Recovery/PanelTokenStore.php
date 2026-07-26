<?php

declare(strict_types=1);

/*
 * This file is part of the "Guardian for TYPO3" extension for TYPO3 CMS.
 *
 * @author    V&T Innovations Team
 * @license   LGPL-3.0-or-later
 * @copyright V&T Innovations 2026 - 2028
 */

namespace Vtinnovations\GuardianTypo3\Infrastructure\Recovery;

use Vtinnovations\GuardianTypo3\Application\Contract\WorkingDirectoryProviderInterface;
use Vtinnovations\GuardianTypo3\Domain\Exception\GuardianException;

/**
 * Secure store for the standalone recovery-panel access token.
 *
 * The token is high-entropy (>= 32 random bytes, URL-safe base64) and is stored
 * ONLY as a SHA-256 hash under var/guardian/recovery-panel/token.json (0600) —
 * never in plaintext. The full token is returned exactly once, at generation or
 * rotation, and is never logged or persisted. Later the store can only reveal a
 * short masked preview. Verification is constant-time via hash_equals(). An
 * environment override (GUARDIAN_RECOVERY_TOKEN) is honoured for pinned tokens.
 */
final class PanelTokenStore
{
    private const FILE = 'recovery-panel/token.json';
    private const ENV_KEYS = ['GUARDIAN_RECOVERY_TOKEN', 'VTINNOVATIONS_GUARDIAN_TOKEN'];

    public function __construct(
        private readonly WorkingDirectoryProviderInterface $workingDirectory,
    ) {
    }

    /**
     * Generates a fresh token, stores only its hash, and returns the plaintext
     * once. Rotation is identical (it replaces the previous hash).
     */
    public function generate(): string
    {
        $token = rtrim(strtr(base64_encode(random_bytes(36)), '+/', '-_'), '=');
        $this->writeJson([
            'algo' => 'sha256',
            'hash' => hash('sha256', $token),
            'preview' => substr($token, 0, 6) . '…' . substr($token, -4),
            'created_at' => gmdate('c'),
        ]);

        return $token;
    }

    public function rotate(): string
    {
        return $this->generate();
    }

    public function exists(): bool
    {
        return $this->envToken() !== null || is_file($this->file());
    }

    /**
     * @return 'env'|'file'|'missing'
     */
    public function source(): string
    {
        if ($this->envToken() !== null) {
            return 'env';
        }

        return is_file($this->file()) ? 'file' : 'missing';
    }

    /**
     * Non-sensitive status for the UI (never contains the plaintext token).
     *
     * @return array{source: string, exists: bool, preview: string, created_at: ?string}
     */
    public function status(): array
    {
        $source = $this->source();
        $data = $this->readJson();

        return [
            'source' => $source,
            'exists' => $source !== 'missing',
            'preview' => $source === 'env' ? '(from environment)' : (string) ($data['preview'] ?? ''),
            'created_at' => $data['created_at'] ?? null,
        ];
    }

    /**
     * Constant-time verification of a presented token.
     */
    public function verify(?string $presented): bool
    {
        if (!\is_string($presented) || $presented === '') {
            return false;
        }
        $env = $this->envToken();
        if ($env !== null) {
            return hash_equals($env, $presented);
        }
        $data = $this->readJson();
        $stored = (string) ($data['hash'] ?? '');
        if ($stored === '') {
            return false;
        }

        return hash_equals($stored, hash('sha256', $presented));
    }

    public function clear(): void
    {
        $file = $this->file();
        if (is_file($file)) {
            @unlink($file);
        }
    }

    private function envToken(): ?string
    {
        foreach (self::ENV_KEYS as $key) {
            $value = getenv($key);
            if (\is_string($value) && trim($value) !== '') {
                return trim($value);
            }
            $server = $_SERVER[$key] ?? $_ENV[$key] ?? null;
            if (\is_string($server) && trim($server) !== '') {
                return trim($server);
            }
        }

        return null;
    }

    /**
     * @return array<string, mixed>
     */
    private function readJson(): array
    {
        $file = $this->file();
        if (!is_file($file)) {
            return [];
        }
        $decoded = json_decode((string) @file_get_contents($file), true);

        return \is_array($decoded) ? $decoded : [];
    }

    /**
     * @param array<string, mixed> $data
     */
    private function writeJson(array $data): void
    {
        $file = $this->file();
        $dir = \dirname($file);
        if (!is_dir($dir) && !@mkdir($dir, 0o750, true) && !is_dir($dir)) {
            throw new GuardianException('Could not create the recovery-panel directory.');
        }
        $json = json_encode($data, \JSON_PRETTY_PRINT | \JSON_UNESCAPED_SLASHES);
        if ($json === false || @file_put_contents($file, $json, \LOCK_EX) === false) {
            throw new GuardianException('Could not store the recovery-panel token.');
        }
        @chmod($file, 0o600);
    }

    private function file(): string
    {
        return $this->workingDirectory->resolve(self::FILE);
    }
}
