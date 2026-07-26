<?php

declare(strict_types=1);

/*
 * This file is part of the "Guardian for TYPO3" extension for TYPO3 CMS.
 *
 * @author    V&T Innovations Team
 * @license   LGPL-3.0-or-later
 * @copyright V&T Innovations 2026 - 2028
 */

namespace Vtinnovations\GuardianTypo3\Application\Update;

use Vtinnovations\GuardianTypo3\Infrastructure\Update\UpdateJobLog;

/**
 * Turns a failed Composer run's output into a STRUCTURED, language-neutral result
 * so the UI can show the real cause (dependency conflict, auth, network, timeout)
 * instead of a bare "Error".
 *
 * The returned `details` are the actual Composer diagnostic lines (the "Problem"
 * / "requires" / "conflicts" explanations), credential-redacted and bounded. The
 * `error_code` and `recommendations` are machine codes translated by the UI — no
 * user-facing English is produced here.
 */
final class ComposerConflictAnalyzer
{
    private const MAX_DETAIL_LINES = 30;

    /**
     * @param int    $exitCode Composer exit code
     * @param string $output   combined stdout+stderr
     * @return array{error_code: string, details: list<string>, recommendations: list<string>}
     */
    public function analyze(int $exitCode, string $output): array
    {
        $safe = UpdateJobLog::redactSecrets($output);
        $lower = strtolower($safe);

        if ($this->looksLikeConflict($lower)) {
            return [
                'error_code' => 'composer_dependency_conflict',
                'details' => $this->extractConflictLines($safe),
                'recommendations' => ['rec_select_older_version', 'rec_update_conflicting_first', 'rec_check_typo3_php'],
            ];
        }
        if (str_contains($lower, 'could not authenticate') || str_contains($lower, ' 401 ') || str_contains($lower, ' 403 ') || str_contains($lower, 'authentication required')) {
            return [
                'error_code' => 'composer_auth_error',
                'details' => $this->firstMeaningfulLines($safe),
                'recommendations' => ['rec_check_auth'],
            ];
        }
        if (str_contains($lower, 'could not resolve host') || str_contains($lower, 'network is unreachable') || str_contains($lower, 'curl error') || str_contains($lower, 'failed to download')) {
            return [
                'error_code' => 'composer_network_error',
                'details' => $this->firstMeaningfulLines($safe),
                'recommendations' => ['rec_retry_later'],
            ];
        }

        return [
            'error_code' => 'composer_error',
            'details' => $this->firstMeaningfulLines($safe),
            'recommendations' => ['rec_check_typo3_php'],
        ];
    }

    private function looksLikeConflict(string $lower): bool
    {
        return str_contains($lower, 'your requirements could not be resolved')
            || str_contains($lower, 'could not be resolved to an installable set')
            || str_contains($lower, "\n  problem ")
            || str_contains($lower, 'problem 1')
            || (str_contains($lower, 'requires ') && str_contains($lower, 'conflict'));
    }

    /**
     * Extract the Composer "Problem"/dependency-explanation block.
     *
     * @return list<string>
     */
    private function extractConflictLines(string $output): array
    {
        $lines = preg_split('/\r?\n/', $output) ?: [];
        $out = [];
        foreach ($lines as $line) {
            $trimmed = trim($line);
            if ($trimmed === '') {
                continue;
            }
            $l = strtolower($trimmed);
            $isProblem = str_starts_with($l, 'problem ')
                || str_starts_with($trimmed, '- ')
                || str_starts_with($trimmed, '- Root composer.json')
                || str_contains($l, 'requires ')
                || str_contains($l, 'conflicts with')
                || str_contains($l, 'but ')
                || str_contains($l, 'no matching package')
                || str_contains($l, 'does not match');
            if ($isProblem) {
                $out[] = $trimmed;
            }
            if (\count($out) >= self::MAX_DETAIL_LINES) {
                break;
            }
        }

        return $out !== [] ? $out : $this->firstMeaningfulLines($output);
    }

    /**
     * @return list<string>
     */
    private function firstMeaningfulLines(string $output): array
    {
        $lines = preg_split('/\r?\n/', $output) ?: [];
        $out = [];
        foreach ($lines as $line) {
            $trimmed = trim($line);
            if ($trimmed === '' || str_starts_with($trimmed, '> ')) {
                continue;
            }
            $out[] = $trimmed;
            if (\count($out) >= self::MAX_DETAIL_LINES) {
                break;
            }
        }

        return $out;
    }
}
