<?php

declare(strict_types=1);

/*
 * This file is part of the "Guardian for TYPO3" extension for TYPO3 CMS.
 *
 * @author    V&T Innovations Team
 * @license   LGPL-3.0-or-later
 * @copyright V&T Innovations 2026 - 2028
 */

namespace Vtinnovations\GuardianTypo3\Infrastructure\Schedule;

use Vtinnovations\GuardianTypo3\Application\Contract\ScheduleConfigStoreInterface;
use Vtinnovations\GuardianTypo3\Application\Contract\WorkingDirectoryProviderInterface;
use Vtinnovations\GuardianTypo3\Domain\Exception\GuardianException;
use Vtinnovations\GuardianTypo3\Domain\Schedule\ScheduleFrequency;

/**
 * JSON-backed scheduled-backup configuration + last-run state, ported from the
 * audited Contao ScheduleConfig/ScheduleState with TYPO3 component keys. All
 * user input is sanitised on save; anything invalid falls back to a safe
 * default so the runner never has to defend against malformed values.
 */
final class JsonScheduleConfigStore implements ScheduleConfigStoreInterface
{
    private const CONFIG_FILE = 'schedule.json';
    private const STATE_FILE = 'schedule_state.json';

    /** Directory components available to a Full profile (TYPO3 layout). */
    private const FULL_COMPONENTS = ['vendor', 'configuration', 'packages', 'templates', 'fileadmin', 'publicAssets'];

    public function __construct(
        private readonly WorkingDirectoryProviderInterface $workingDirectory,
    ) {
    }

    public function loadConfig(): array
    {
        return $this->mergeWithDefaults($this->readJson(self::CONFIG_FILE));
    }

    public function saveConfig(array $raw): array
    {
        $clean = $this->validate($raw);
        $this->writeJson(self::CONFIG_FILE, $clean);

        return $clean;
    }

    public function loadState(): array
    {
        $state = $this->readJson(self::STATE_FILE);

        return [
            'mini' => \is_array($state['mini'] ?? null) ? $state['mini'] : null,
            'full' => \is_array($state['full'] ?? null) ? $state['full'] : null,
        ];
    }

    public function recordRun(string $type, string $status, string $message, ?string $backupId): void
    {
        $state = $this->readJson(self::STATE_FILE);
        $state[$type] = [
            'last_run' => gmdate('c'),
            'last_status' => $status,
            'last_message' => $message,
            'last_backup' => $backupId,
        ];
        $this->writeJson(self::STATE_FILE, $state);
    }

    /**
     * @return array<string, mixed>
     */
    public function defaults(): array
    {
        return [
            'mini' => [
                'enabled' => false,
                'frequency' => ScheduleFrequency::Daily->value,
                'time' => '03:00',
                'weekday' => 1,
                'day_of_month' => 1,
                'retention' => 7,
            ],
            'full' => [
                'enabled' => false,
                'frequency' => ScheduleFrequency::Weekly->value,
                'time' => '02:00',
                'weekday' => 0,
                'day_of_month' => 1,
                'retention' => 4,
                'components' => [
                    'vendor' => true,
                    'configuration' => true,
                    'packages' => true,
                    'templates' => false,
                    'fileadmin' => false,
                    'publicAssets' => false,
                ],
            ],
            'storage_path' => '',
            'notifications' => [
                'email' => '',
                'sender_email' => '',
                'sender_name' => 'Guardian',
                'on_success' => false,
                'on_failure' => true,
            ],
        ];
    }

    /**
     * @param array<string, mixed> $data
     * @return array<string, mixed>
     */
    private function mergeWithDefaults(array $data): array
    {
        $defaults = $this->defaults();
        $merged = [
            'mini' => array_merge($defaults['mini'], \is_array($data['mini'] ?? null) ? $data['mini'] : []),
            'full' => array_merge($defaults['full'], \is_array($data['full'] ?? null) ? $data['full'] : []),
            // storage_path is accepted but not honoured: backups always live under
            // var/guardian/backups (outside the web root) for safety.
            'storage_path' => '',
            'notifications' => array_merge($defaults['notifications'], \is_array($data['notifications'] ?? null) ? $data['notifications'] : []),
        ];
        $merged['full']['components'] = array_merge(
            $defaults['full']['components'],
            \is_array($data['full']['components'] ?? null) ? $data['full']['components'] : []
        );

        return $merged;
    }

    /**
     * @param array<string, mixed> $config
     * @return array<string, mixed>
     */
    private function validate(array $config): array
    {
        $defaults = $this->defaults();
        $valid = $this->mergeWithDefaults($config);

        foreach (['mini', 'full'] as $slot) {
            $valid[$slot]['enabled'] = (bool) $valid[$slot]['enabled'];
            if (ScheduleFrequency::tryFrom((string) $valid[$slot]['frequency']) === null) {
                $valid[$slot]['frequency'] = $defaults[$slot]['frequency'];
            }
            if (preg_match('/^([01]\d|2[0-3]):([0-5]\d)$/', (string) $valid[$slot]['time']) !== 1) {
                $valid[$slot]['time'] = $defaults[$slot]['time'];
            }
            $valid[$slot]['weekday'] = max(0, min(6, (int) $valid[$slot]['weekday']));
            $valid[$slot]['day_of_month'] = max(1, min(28, (int) $valid[$slot]['day_of_month']));
            $valid[$slot]['retention'] = max(1, min(999, (int) $valid[$slot]['retention']));
        }

        $components = [];
        foreach (self::FULL_COMPONENTS as $key) {
            $components[$key] = (bool) ($valid['full']['components'][$key] ?? false);
        }
        $valid['full']['components'] = $components;

        $notifications = $valid['notifications'];
        $email = trim((string) ($notifications['email'] ?? ''));
        $notifications['email'] = ($email !== '' && filter_var($email, \FILTER_VALIDATE_EMAIL)) ? $email : '';
        $sender = trim((string) ($notifications['sender_email'] ?? ''));
        $notifications['sender_email'] = ($sender !== '' && filter_var($sender, \FILTER_VALIDATE_EMAIL)) ? $sender : '';
        $senderName = trim((string) ($notifications['sender_name'] ?? 'Guardian'));
        $notifications['sender_name'] = ($senderName === '' || mb_strlen($senderName) > 100) ? 'Guardian' : $senderName;
        $notifications['on_success'] = (bool) ($notifications['on_success'] ?? false);
        $notifications['on_failure'] = (bool) ($notifications['on_failure'] ?? true);
        $valid['notifications'] = $notifications;
        $valid['storage_path'] = '';

        return $valid;
    }

    /**
     * @return array<string, mixed>
     */
    private function readJson(string $filename): array
    {
        $file = $this->workingDirectory->resolve($filename);
        if (!is_file($file)) {
            return [];
        }
        $decoded = json_decode((string) @file_get_contents($file), true);

        return \is_array($decoded) ? $decoded : [];
    }

    /**
     * @param array<string, mixed> $data
     */
    private function writeJson(string $filename, array $data): void
    {
        $file = $this->workingDirectory->resolve($filename);
        $dir = \dirname($file);
        if (!is_dir($dir) && !@mkdir($dir, 0o750, true) && !is_dir($dir)) {
            throw new GuardianException('Could not create the Guardian working directory.');
        }
        $json = json_encode($data, \JSON_PRETTY_PRINT | \JSON_UNESCAPED_SLASHES | \JSON_UNESCAPED_UNICODE);
        if ($json === false || @file_put_contents($file, $json, \LOCK_EX) === false) {
            throw new GuardianException('Could not write ' . $filename . '.');
        }
        @chmod($file, 0o640);
    }
}
