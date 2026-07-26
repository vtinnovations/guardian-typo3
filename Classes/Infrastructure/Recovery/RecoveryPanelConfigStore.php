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
use Vtinnovations\GuardianTypo3\Domain\Recovery\PanelFilename;

/**
 * Persistent configuration + audit log for the standalone recovery panel, stored
 * OUTSIDE the web root under var/guardian/recovery-panel/ with restrictive
 * permissions. The panel is DISABLED by default. All state-changing actions are
 * appended to an audit log that never records the token, license key or database
 * password.
 */
final class RecoveryPanelConfigStore
{
    private const CONFIG = 'recovery-panel/config.json';
    private const AUDIT = 'recovery-panel/audit.log';

    public function __construct(
        private readonly WorkingDirectoryProviderInterface $workingDirectory,
    ) {
    }

    /**
     * @return array{enabled: bool, filename: string}
     */
    public function load(): array
    {
        $data = $this->readJson();

        $filename = (string) ($data['filename'] ?? PanelFilename::DEFAULT);
        if (!PanelFilename::isValid($filename)) {
            $filename = PanelFilename::DEFAULT;
        }

        return [
            'enabled' => (bool) ($data['enabled'] ?? false),
            'filename' => $filename,
        ];
    }

    public function isEnabled(): bool
    {
        return $this->load()['enabled'];
    }

    public function filename(): string
    {
        return $this->load()['filename'];
    }

    public function setEnabled(bool $enabled): void
    {
        $config = $this->load();
        $config['enabled'] = $enabled;
        $this->write($config);
    }

    /**
     * @throws GuardianException on an invalid filename
     */
    public function setFilename(string $filename): string
    {
        $valid = PanelFilename::fromString($filename)->value;
        $config = $this->load();
        $config['filename'] = $valid;
        $this->write($config);

        return $valid;
    }

    /**
     * Appends a non-sensitive audit entry.
     *
     * @param array<string, scalar|null> $meta
     */
    public function audit(string $event, array $meta = []): void
    {
        $file = $this->workingDirectory->resolve(self::AUDIT);
        $dir = \dirname($file);
        if (!is_dir($dir)) {
            @mkdir($dir, 0o750, true);
        }
        $line = gmdate('c') . ' ' . $event;
        foreach ($meta as $key => $value) {
            $line .= ' ' . $key . '=' . $this->scalar($value);
        }
        @file_put_contents($file, $line . "\n", \FILE_APPEND | \LOCK_EX);
        @chmod($file, 0o640);
    }

    /**
     * @return list<string>
     */
    public function auditTail(int $lines = 30): array
    {
        $file = $this->workingDirectory->resolve(self::AUDIT);
        if (!is_file($file)) {
            return [];
        }
        $all = explode("\n", rtrim((string) @file_get_contents($file), "\n"));

        return array_values(\array_slice($all, -$lines));
    }

    /**
     * @param array{enabled: bool, filename: string} $config
     */
    private function write(array $config): void
    {
        $file = $this->workingDirectory->resolve(self::CONFIG);
        $dir = \dirname($file);
        if (!is_dir($dir) && !@mkdir($dir, 0o750, true) && !is_dir($dir)) {
            throw new GuardianException('Could not create the recovery-panel directory.');
        }
        $json = json_encode($config, \JSON_PRETTY_PRINT | \JSON_UNESCAPED_SLASHES);
        if ($json === false || @file_put_contents($file, $json, \LOCK_EX) === false) {
            throw new GuardianException('Could not write the recovery-panel configuration.');
        }
        @chmod($file, 0o640);
    }

    /**
     * @return array<string, mixed>
     */
    private function readJson(): array
    {
        $file = $this->workingDirectory->resolve(self::CONFIG);
        if (!is_file($file)) {
            return [];
        }
        $decoded = json_decode((string) @file_get_contents($file), true);

        return \is_array($decoded) ? $decoded : [];
    }

    private function scalar(mixed $value): string
    {
        if (\is_bool($value)) {
            return $value ? 'true' : 'false';
        }

        return preg_replace('/\s+/', '_', (string) $value) ?? '';
    }
}
