<?php

declare(strict_types=1);

/*
 * This file is part of the "Guardian for TYPO3" extension for TYPO3 CMS.
 *
 * @author    V&T Innovations Team
 * @license   LGPL-3.0-or-later
 * @copyright V&T Innovations 2026 - 2028
 */

namespace Vtinnovations\GuardianTypo3\Infrastructure\Database;

use Symfony\Component\Process\ExecutableFinder;
use Symfony\Component\Process\Process;
use Vtinnovations\GuardianTypo3\Application\Backup\DatabaseDumpResult;
use Vtinnovations\GuardianTypo3\Application\Contract\DatabaseDumperInterface;
use Vtinnovations\GuardianTypo3\Domain\Exception\GuardianException;

/**
 * Dumps the active TYPO3 (MySQL/MariaDB) database to a file.
 *
 * Ported from the audited Contao BackupManager DB logic and adapted to TYPO3:
 * the connection config is read server-side from TYPO3_CONF_VARS, credentials
 * are never exposed to the browser or logged, and for the external dumper the
 * password is passed through a 0600 temporary defaults file (never on the
 * command line / process list). Two strategies, both streaming to disk:
 *
 *   1. mysqldump / mariadb-dump via Symfony Process (array argv, no shell,
 *      stdout streamed straight to the target file, stderr captured, timeout).
 *   2. A pure-PHP UNBUFFERED PDO exporter (matching the original's robust
 *      streaming exporter) — used when the external binary is unavailable.
 *
 * Non-MySQL drivers fail explicitly; the dumper never reports success without a
 * real dump on disk.
 */
final class Typo3DatabaseDumper implements DatabaseDumperInterface
{
    private const MYSQL_DRIVERS = ['mysqli', 'pdo_mysql'];

    public function dumpTo(string $targetFile, callable $log): DatabaseDumpResult
    {
        $config = $this->connectionConfig();
        $driver = (string) ($config['driver'] ?? '');
        if (!\in_array($driver, self::MYSQL_DRIVERS, true)) {
            throw new GuardianException(sprintf(
                'Database backup currently supports MySQL/MariaDB only; the active connection uses "%s".',
                $driver !== '' ? $driver : 'unknown'
            ));
        }
        if (($config['dbname'] ?? '') === '') {
            throw new GuardianException('No database name is configured for the default TYPO3 connection.');
        }

        $binary = (new ExecutableFinder())->find('mysqldump') ?? (new ExecutableFinder())->find('mariadb-dump');
        if ($binary !== null) {
            try {
                return $this->dumpWithBinary($binary, $config, $targetFile, $log);
            } catch (GuardianException $e) {
                $log('mysqldump failed (' . $e->getMessage() . ') — falling back to the PHP exporter.');
            }
        } else {
            $log('mysqldump/mariadb-dump not found — using the PHP exporter.');
        }

        return $this->dumpWithPdo($config, $targetFile, $log);
    }

    /**
     * @param array<string, mixed> $config
     */
    private function dumpWithBinary(string $binary, array $config, string $targetFile, callable $log): DatabaseDumpResult
    {
        $defaultsFile = $targetFile . '.cnf';
        $this->writeDefaultsFile($defaultsFile, $config);

        $handle = @fopen($targetFile, 'wb');
        if ($handle === false) {
            @unlink($defaultsFile);
            throw new GuardianException('Could not open the database dump file for writing.');
        }

        $stderr = '';
        try {
            $process = new Process([
                $binary,
                '--defaults-extra-file=' . $defaultsFile,
                '--no-tablespaces',
                '--single-transaction',
                '--skip-lock-tables',
                '--routines',
                '--triggers',
                '--add-drop-table',
                '--default-character-set=utf8mb4',
                (string) $config['dbname'],
            ]);
            $process->setTimeout(3600.0);
            $log('Running ' . basename($binary) . ' (credentials via temporary defaults file).');
            $process->run(function (string $type, string $buffer) use ($handle, &$stderr): void {
                if ($type === Process::OUT) {
                    fwrite($handle, $buffer);
                } else {
                    $stderr .= $buffer;
                }
            });

            if (!$process->isSuccessful()) {
                throw new GuardianException('exit ' . $process->getExitCode() . ': ' . $this->firstLine($stderr));
            }
        } finally {
            fclose($handle);
            @unlink($defaultsFile);
        }

        $bytes = (int) @filesize($targetFile);
        if ($bytes < 40) {
            throw new GuardianException('the dump file is empty or truncated.');
        }
        $log(sprintf('Database dumped via %s (%d bytes).', basename($binary), $bytes));

        return new DatabaseDumpResult($bytes, basename($binary), $this->safeServerVersion($config));
    }

    /**
     * @param array<string, mixed> $config
     */
    private function dumpWithPdo(array $config, string $targetFile, callable $log): DatabaseDumpResult
    {
        if (!class_exists(\PDO::class) || !\in_array('mysql', \PDO::getAvailableDrivers(), true)) {
            throw new GuardianException('Cannot dump the database: neither mysqldump nor the PDO MySQL driver is available.');
        }

        $pdo = $this->createPdo($config, false);
        $handle = @fopen($targetFile, 'wb');
        if ($handle === false) {
            throw new GuardianException('Could not open the database dump file for writing.');
        }

        try {
            fwrite($handle, "-- Guardian for TYPO3 database dump\n");
            fwrite($handle, '-- Generated: ' . gmdate('c') . "\n");
            fwrite($handle, "SET FOREIGN_KEY_CHECKS=0;\nSET NAMES utf8mb4;\n\n");

            $tables = $pdo->query('SHOW TABLES')->fetchAll(\PDO::FETCH_COLUMN) ?: [];
            foreach ($tables as $table) {
                $quoted = '`' . str_replace('`', '``', (string) $table) . '`';
                fwrite($handle, "DROP TABLE IF EXISTS {$quoted};\n");
                $create = $pdo->query("SHOW CREATE TABLE {$quoted}")->fetch(\PDO::FETCH_NUM);
                if (\is_array($create) && isset($create[1])) {
                    fwrite($handle, $create[1] . ";\n\n");
                }

                // Unbuffered iteration — rows stream one at a time, so even large
                // tables do not have to fit into memory.
                $stmt = $pdo->query("SELECT * FROM {$quoted}");
                $batch = [];
                while (($row = $stmt->fetch(\PDO::FETCH_NUM)) !== false) {
                    $values = array_map(
                        static function ($value) use ($pdo): string {
                            if ($value === null) {
                                return 'NULL';
                            }
                            $quotedValue = $pdo->quote((string) $value);

                            return $quotedValue === false ? '0x' . bin2hex((string) $value) : $quotedValue;
                        },
                        $row
                    );
                    $batch[] = '(' . implode(',', $values) . ')';
                    if (\count($batch) >= 200) {
                        fwrite($handle, "INSERT INTO {$quoted} VALUES\n" . implode(",\n", $batch) . ";\n");
                        $batch = [];
                    }
                }
                if ($batch !== []) {
                    fwrite($handle, "INSERT INTO {$quoted} VALUES\n" . implode(",\n", $batch) . ";\n");
                }
                fwrite($handle, "\n");
            }

            fwrite($handle, "SET FOREIGN_KEY_CHECKS=1;\n");
        } catch (\PDOException $e) {
            fclose($handle);
            throw new GuardianException('Database export failed: ' . $this->firstLine($e->getMessage()));
        }
        fclose($handle);

        $bytes = (int) @filesize($targetFile);
        $log(sprintf('Database dumped via PHP/PDO (%d bytes).', $bytes));

        return new DatabaseDumpResult($bytes, 'php-pdo', $this->safeServerVersion($config));
    }

    /**
     * @param array<string, mixed> $config
     */
    private function createPdo(array $config, bool $buffered): \PDO
    {
        $dsn = sprintf(
            'mysql:host=%s;port=%d;dbname=%s;charset=utf8mb4',
            (string) ($config['host'] ?? 'localhost'),
            (int) ($config['port'] ?? 3306),
            (string) $config['dbname']
        );

        try {
            return new \PDO($dsn, (string) ($config['user'] ?? ''), (string) ($config['password'] ?? ''), [
                \PDO::ATTR_ERRMODE => \PDO::ERRMODE_EXCEPTION,
                \PDO::MYSQL_ATTR_USE_BUFFERED_QUERY => $buffered,
            ]);
        } catch (\PDOException $e) {
            throw new GuardianException('Could not connect to the database for backup.');
        }
    }

    /**
     * @param array<string, mixed> $config
     */
    private function writeDefaultsFile(string $file, array $config): void
    {
        $lines = ['[client]'];
        $lines[] = 'host=' . (string) ($config['host'] ?? 'localhost');
        $lines[] = 'port=' . (int) ($config['port'] ?? 3306);
        $lines[] = 'user=' . (string) ($config['user'] ?? '');
        $lines[] = 'password=' . (string) ($config['password'] ?? '');

        if (@file_put_contents($file, implode("\n", $lines) . "\n", \LOCK_EX) === false) {
            throw new GuardianException('Could not write the temporary database credentials file.');
        }
        @chmod($file, 0o600);
    }

    /**
     * @param array<string, mixed> $config
     */
    private function safeServerVersion(array $config): string
    {
        try {
            $pdo = $this->createPdo($config, true);
            $version = $pdo->query('SELECT VERSION()')->fetchColumn();

            return \is_string($version) ? $version : '';
        } catch (\Throwable) {
            return '';
        }
    }

    /**
     * @return array<string, mixed>
     */
    private function connectionConfig(): array
    {
        $connections = $GLOBALS['TYPO3_CONF_VARS']['DB']['Connections'] ?? [];
        $config = $connections['Default'] ?? [];

        return \is_array($config) ? $config : [];
    }

    private function firstLine(string $text): string
    {
        $text = trim($text);
        $pos = strpos($text, "\n");

        return $pos === false ? $text : substr($text, 0, $pos);
    }
}
