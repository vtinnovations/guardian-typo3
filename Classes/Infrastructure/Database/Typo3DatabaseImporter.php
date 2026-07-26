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
use Vtinnovations\GuardianTypo3\Application\Contract\DatabaseImporterInterface;
use Vtinnovations\GuardianTypo3\Domain\Exception\GuardianException;

/**
 * Imports a plain-SQL dump into the active TYPO3 (MySQL/MariaDB) database.
 *
 * Ports the streaming importer from the audited Contao RestoreManager: it
 * prefers the external mysql/mariadb client (fast, credentials via a 0600
 * defaults file, the dump streamed to stdin) and falls back to a chunked PDO
 * statement replayer that never loads the whole dump into memory. Fatal errors
 * abort; harmless "table exists"/"unknown table" errors during replay are
 * tolerated. Non-MySQL drivers fail explicitly.
 */
final class Typo3DatabaseImporter implements DatabaseImporterInterface
{
    private const MYSQL_DRIVERS = ['mysqli', 'pdo_mysql'];

    public function isSupported(): bool
    {
        return \in_array($this->driver(), self::MYSQL_DRIVERS, true);
    }

    public function driver(): string
    {
        return (string) ($this->config()['driver'] ?? '');
    }

    public function canConnect(): bool
    {
        try {
            $this->createPdo()->query('SELECT 1');

            return true;
        } catch (\Throwable) {
            return false;
        }
    }

    public function importFrom(string $sqlFile, callable $log): void
    {
        if (!$this->isSupported()) {
            throw new GuardianException(sprintf('Database restore supports MySQL/MariaDB only; driver "%s".', $this->driver() ?: 'unknown'));
        }
        if (!is_file($sqlFile) || filesize($sqlFile) === 0) {
            throw new GuardianException('The database dump inside the backup is missing or empty.');
        }

        $binary = (new ExecutableFinder())->find('mysql') ?? (new ExecutableFinder())->find('mariadb');
        if ($binary !== null) {
            try {
                $this->importWithBinary($binary, $sqlFile, $log);

                return;
            } catch (GuardianException $e) {
                $log('mysql import failed (' . $e->getMessage() . ') — falling back to the PHP importer.');
            }
        } else {
            $log('mysql/mariadb client not found — using the PHP importer.');
        }

        $this->importWithPdo($sqlFile, $log);
    }

    private function importWithBinary(string $binary, string $sqlFile, callable $log): void
    {
        $config = $this->config();
        $defaultsFile = $sqlFile . '.cnf';
        $this->writeDefaultsFile($defaultsFile, $config);

        $input = @fopen($sqlFile, 'rb');
        if ($input === false) {
            @unlink($defaultsFile);
            throw new GuardianException('Could not read the database dump for import.');
        }

        $stderr = '';
        try {
            $process = new Process([
                $binary,
                '--defaults-extra-file=' . $defaultsFile,
                '--default-character-set=utf8mb4',
                (string) $config['dbname'],
            ]);
            $process->setTimeout(3600.0);
            $process->setInput($input); // streamed stdin — the dump is not buffered in memory
            $log('Importing database via ' . basename($binary) . ' (credentials via temporary defaults file).');
            $process->run(function (string $type, string $buffer) use (&$stderr): void {
                if ($type === Process::ERR) {
                    $stderr .= $buffer;
                }
            });
            if (!$process->isSuccessful()) {
                throw new GuardianException('exit ' . $process->getExitCode() . ': ' . $this->firstLine($stderr));
            }
        } finally {
            if (\is_resource($input)) {
                fclose($input);
            }
            @unlink($defaultsFile);
        }
        $log('Database imported via ' . basename($binary) . '.');
    }

    private function importWithPdo(string $sqlFile, callable $log): void
    {
        $pdo = $this->createPdo();
        $handle = @fopen($sqlFile, 'rb');
        if ($handle === false) {
            throw new GuardianException('Could not read the database dump for import.');
        }

        try {
            $pdo->exec('SET FOREIGN_KEY_CHECKS=0');
        } catch (\PDOException) {
            // best effort
        }

        $buffer = '';
        $count = 0;
        $errors = 0;
        try {
            while (!feof($handle)) {
                $buffer .= (string) fread($handle, 65536);
                while (($pos = strpos($buffer, ";\n")) !== false) {
                    $statement = trim(substr($buffer, 0, $pos));
                    $buffer = substr($buffer, $pos + 2);
                    $errors += $this->execStatement($pdo, $statement);
                    $count++;
                    if ($count % 500 === 0) {
                        $log(sprintf('  imported %d statements…', $count));
                    }
                }
            }
            $tail = trim($buffer);
            if ($tail !== '') {
                $errors += $this->execStatement($pdo, $tail);
            }
        } finally {
            fclose($handle);
            try {
                $pdo->exec('SET FOREIGN_KEY_CHECKS=1');
            } catch (\PDOException) {
                // best effort
            }
        }

        $log(sprintf('Database imported via PHP/PDO (%d statements, %d tolerated warnings).', $count, $errors));
    }

    /**
     * @return int 1 if a tolerated warning occurred, 0 otherwise
     */
    private function execStatement(\PDO $pdo, string $statement): int
    {
        if ($statement === '' || str_starts_with($statement, '--') || str_starts_with($statement, '/*')) {
            return 0;
        }
        try {
            $pdo->exec($statement);

            return 0;
        } catch (\PDOException $e) {
            $message = strtolower($e->getMessage());
            foreach (['server has gone away', 'lost connection', 'access denied', 'out of memory', 'disk full', 'no space left'] as $fatal) {
                if (str_contains($message, $fatal)) {
                    throw new GuardianException('Fatal database error during restore: ' . $this->firstLine($e->getMessage()));
                }
            }
            // Harmless (already exists / unknown table) — tolerate.
            return 1;
        }
    }

    private function createPdo(): \PDO
    {
        $config = $this->config();
        if (($config['dbname'] ?? '') === '') {
            throw new GuardianException('No database is configured for the default TYPO3 connection.');
        }
        $dsn = sprintf(
            'mysql:host=%s;port=%d;dbname=%s;charset=utf8mb4',
            (string) ($config['host'] ?? 'localhost'),
            (int) ($config['port'] ?? 3306),
            (string) $config['dbname']
        );
        try {
            return new \PDO($dsn, (string) ($config['user'] ?? ''), (string) ($config['password'] ?? ''), [
                \PDO::ATTR_ERRMODE => \PDO::ERRMODE_EXCEPTION,
            ]);
        } catch (\PDOException) {
            throw new GuardianException('Could not connect to the database for restore.');
        }
    }

    /**
     * @param array<string, mixed> $config
     */
    private function writeDefaultsFile(string $file, array $config): void
    {
        $lines = [
            '[client]',
            'host=' . (string) ($config['host'] ?? 'localhost'),
            'port=' . (int) ($config['port'] ?? 3306),
            'user=' . (string) ($config['user'] ?? ''),
            'password=' . (string) ($config['password'] ?? ''),
        ];
        if (@file_put_contents($file, implode("\n", $lines) . "\n", \LOCK_EX) === false) {
            throw new GuardianException('Could not write the temporary database credentials file.');
        }
        @chmod($file, 0o600);
    }

    /**
     * @return array<string, mixed>
     */
    private function config(): array
    {
        $config = $GLOBALS['TYPO3_CONF_VARS']['DB']['Connections']['Default'] ?? [];

        return \is_array($config) ? $config : [];
    }

    private function firstLine(string $text): string
    {
        $text = trim($text);
        $pos = strpos($text, "\n");

        return $pos === false ? $text : substr($text, 0, $pos);
    }
}
