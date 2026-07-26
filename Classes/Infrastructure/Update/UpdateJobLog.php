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

use Vtinnovations\GuardianTypo3\Application\Contract\WorkingDirectoryProviderInterface;

/**
 * Append-only, offset-readable job log for the update worker, stored under
 * var/guardian/update/job.log. Each line is a JSON object
 * {ts, level, step, msg}. The worker appends; the backend polls by byte offset
 * so the browser sees live progress.
 *
 * Every message is passed through {@see redactSecrets()} before it is written,
 * so a database password, Composer auth token or DSN can never leak into the log
 * even if a child process echoes it. Ported from the audited Contao JobLog.
 */
final class UpdateJobLog
{
    private const FILE = 'update/job.log';

    public function __construct(
        private readonly WorkingDirectoryProviderInterface $workingDirectory,
    ) {
    }

    public function reset(): void
    {
        $file = $this->file();
        $dir = \dirname($file);
        if (!is_dir($dir)) {
            @mkdir($dir, 0o750, true);
        }
        @file_put_contents($file, '');
        @chmod($file, 0o640);
    }

    public function info(string $step, string $message): void
    {
        $this->write('info', $step, $message);
    }

    public function warning(string $step, string $message): void
    {
        $this->write('warning', $step, $message);
    }

    public function error(string $step, string $message): void
    {
        $this->write('error', $step, $message);
    }

    public function step(string $step, string $message): void
    {
        $this->write('step', $step, $message);
    }

    /**
     * Reads log entries from a byte offset, returning the entries and the new
     * offset for the next poll.
     *
     * @return array{entries: list<array<string, mixed>>, offset: int}
     */
    public function readSince(int $offset = 0): array
    {
        $file = $this->file();
        if (!is_file($file)) {
            return ['entries' => [], 'offset' => 0];
        }
        clearstatcache(false, $file);
        $size = (int) filesize($file);
        if ($offset < 0 || $offset > $size) {
            $offset = 0;
        }
        if ($size === 0 || $offset === $size) {
            return ['entries' => [], 'offset' => $size];
        }
        $fp = @fopen($file, 'rb');
        if ($fp === false) {
            return ['entries' => [], 'offset' => $offset];
        }
        @fseek($fp, $offset);
        $content = (string) stream_get_contents($fp);
        $newOffset = ftell($fp);
        fclose($fp);

        $entries = [];
        foreach (explode("\n", $content) as $line) {
            $line = trim($line);
            if ($line === '') {
                continue;
            }
            $decoded = json_decode($line, true);
            if (\is_array($decoded)) {
                $entries[] = $decoded;
            }
        }

        return ['entries' => $entries, 'offset' => $newOffset !== false ? $newOffset : $size];
    }

    private function write(string $level, string $step, string $message): void
    {
        $file = $this->file();
        $dir = \dirname($file);
        if (!is_dir($dir)) {
            @mkdir($dir, 0o750, true);
        }
        $entry = json_encode([
            'ts' => gmdate('c'),
            'level' => $level,
            'step' => $step,
            'msg' => self::redactSecrets($message),
        ], \JSON_UNESCAPED_UNICODE | \JSON_UNESCAPED_SLASHES);
        if ($entry !== false) {
            @file_put_contents($file, $entry . "\n", \FILE_APPEND | \LOCK_EX);
            @chmod($file, 0o640);
        }
    }

    /**
     * Strips obvious secrets from a message before it is persisted. Ported
     * verbatim (behaviourally) from the audited Contao JobLog.
     */
    public static function redactSecrets(string $message): string
    {
        $message = preg_replace('/(--(?:password|pwd|pass))=\S+/i', '$1=***', $message) ?? $message;
        $message = preg_replace('/(--(?:password|pwd|pass))\s+\S+/i', '$1 ***', $message) ?? $message;
        $message = preg_replace('/(?<!\w)-p[A-Za-z0-9!@#$%^&*()_+\-=\[\]{};:\'",.<>\/?\\\\|`~]+/', '-p***', $message) ?? $message;
        $message = preg_replace('/((?:password|pwd|pass|secret|token|api_key|MYSQL_PWD|mysql_pwd))=\S+/i', '$1=***', $message) ?? $message;
        $message = preg_replace('/(Bearer\s+)\S+/i', '$1***', $message) ?? $message;
        $message = preg_replace('#([a-z][a-z0-9+.-]*://[^/@\s:]+):[^@\s]+@#i', '$1:***@', $message) ?? $message;

        return $message;
    }

    private function file(): string
    {
        return $this->workingDirectory->resolve(self::FILE);
    }
}
