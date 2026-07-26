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

use Vtinnovations\GuardianTypo3\Domain\Exception\GuardianException;
use Vtinnovations\GuardianTypo3\Domain\Job\UpdateMode;
use Vtinnovations\GuardianTypo3\Domain\Process\CommandRequest;
use Vtinnovations\GuardianTypo3\Domain\Update\PackageName;
use Vtinnovations\GuardianTypo3\Domain\Update\PackageRequirement;

/**
 * Builds the exact Composer command lines for each update mode as validated argv
 * arrays. This is the authoritative, shell-free command construction for the
 * whole update subsystem and is deliberately pure (no filesystem, no process) so
 * it can be unit-tested exhaustively.
 *
 * Composer is ALWAYS invoked as `<php-cli> <composer.phar> …` — never through a
 * shell wrapper — so Composer's platform checks see the same PHP extension set
 * as the site runtime (the classic Plesk CLI/web mismatch the Contao original
 * fought). Every argument is a separate argv element; nothing is concatenated,
 * so no package name or flag can ever be injected.
 *
 * Exact commands (baseline safety flags in brackets):
 *   [B] = --no-interaction --no-progress --no-scripts
 *   Full         : composer update [B] --with-all-dependencies         (+ignore flags)
 *   Conservative : composer update [B] --prefer-stable                 (+ignore flags)
 *                  (no --with-all-dependencies → transitive deps stay put where possible)
 *   Selective    : composer update <pkg…> [B] --with-dependencies      (+ignore flags)
 *   Dry run      : the selected mode's command with --dry-run appended.
 * `--no-scripts` is used so Composer's own post-update scripts don't run inside
 * the resolver step; database schema + cache are applied as explicit later steps.
 */
final class ComposerCommandFactory
{
    /** Baseline flags shared by every mode. */
    private const BASE_FLAGS = ['--no-interaction', '--no-progress', '--no-scripts'];

    public function __construct(
        private readonly string $phpBinary,
        private readonly string $composerBinary,
        private readonly string $projectDir,
    ) {
        if (trim($this->phpBinary) === '') {
            throw new GuardianException('No PHP CLI binary is configured for Composer.');
        }
        if (trim($this->composerBinary) === '') {
            throw new GuardianException('No Composer binary was found.');
        }
    }

    /**
     * The real (or dry-run) update command for a mode.
     *
     * @param list<string> $selectedPackages only used for selective mode
     * @param list<string> $ignorePlatformFlags e.g. ['--ignore-platform-req=ext-intl']
     */
    public function forMode(
        UpdateMode $mode,
        array $selectedPackages,
        bool $dryRun,
        array $ignorePlatformFlags = [],
        int $timeoutSeconds = 1800,
    ): CommandRequest {
        $args = ['update'];

        switch ($mode) {
            case UpdateMode::Full:
                $args = array_merge($args, self::BASE_FLAGS, ['--with-all-dependencies']);
                break;

            case UpdateMode::Patch:
                // Conservative: no --with-all-dependencies, so Composer avoids
                // dragging transitive dependencies along. --prefer-stable avoids
                // pre-releases. This genuinely minimises dependency movement and
                // is NOT relabelled full dependency resolution.
                $args = array_merge($args, self::BASE_FLAGS, ['--prefer-stable']);
                break;

            case UpdateMode::Selective:
                $packages = PackageName::validateList($selectedPackages);
                if ($packages === []) {
                    throw new GuardianException('Selective mode requires at least one valid package.');
                }
                // Package names are validated argv elements; --with-dependencies
                // (not --with-ALL-dependencies) lets only the selected packages'
                // own dependencies move.
                $args = array_merge($args, $packages, self::BASE_FLAGS, ['--with-dependencies']);
                break;
        }

        $args = array_merge($args, $this->sanitizeIgnoreFlags($ignorePlatformFlags));

        if ($dryRun) {
            $args[] = '--dry-run';
        }

        return $this->composer($args, $timeoutSeconds);
    }

    /**
     * `composer remove <pkg…>` for a Dashboard package removal, as validated argv.
     * Package names are validated as separate argv elements (no injection). A
     * path-repository package's SOURCE under packages/ is untouched by
     * `composer remove` — only the require entry + vendor symlink are removed.
     *
     * @param list<string> $packages
     * @param list<string> $ignorePlatformFlags
     */
    public function remove(array $packages, bool $dryRun, array $ignorePlatformFlags = [], int $timeoutSeconds = 1800): CommandRequest
    {
        $validated = PackageName::validateList($packages);
        if ($validated === []) {
            throw new GuardianException('A package removal requires at least one valid package.');
        }
        $args = array_merge(['remove'], $validated, self::BASE_FLAGS);
        $args = array_merge($args, $this->sanitizeIgnoreFlags($ignorePlatformFlags));
        if ($dryRun) {
            $args[] = '--dry-run';
        }

        return $this->composer($args, $timeoutSeconds);
    }

    /**
     * `composer require <req…>` for installing a TER/Packagist package. Each
     * requirement is a validated {@see PackageRequirement} (`vendor/name` or
     * `vendor/name:constraint`) — never a raw browser string — so nothing can
     * become a flag. When no constraint is given Composer itself resolves the
     * latest version compatible with the current TYPO3 + PHP + lock constraints.
     *
     * @param list<string> $requirements
     * @param list<string> $ignorePlatformFlags
     */
    public function require(array $requirements, bool $dryRun, array $ignorePlatformFlags = [], int $timeoutSeconds = 1800): CommandRequest
    {
        $validated = PackageRequirement::validateList($requirements);
        if ($validated === []) {
            throw new GuardianException('A package installation requires at least one valid requirement.');
        }
        $args = array_merge(['require'], $validated, self::BASE_FLAGS, ['--with-all-dependencies']);
        $args = array_merge($args, $this->sanitizeIgnoreFlags($ignorePlatformFlags));
        if ($dryRun) {
            $args[] = '--dry-run';
        }

        return $this->composer($args, $timeoutSeconds);
    }

    /**
     * Read-only "what is outdated" command producing machine-parseable JSON.
     *
     * @param list<string> $ignorePlatformFlags
     */
    public function outdated(bool $directOnly = true, array $ignorePlatformFlags = []): CommandRequest
    {
        $args = ['outdated', '--no-interaction', '--format=json'];
        if ($directOnly) {
            $args[] = '--direct';
        }
        $args = array_merge($args, $this->sanitizeIgnoreFlags($ignorePlatformFlags));

        return $this->composer($args, 180);
    }

    public function showCoreReleases(array $ignorePlatformFlags = []): CommandRequest
    {
        return $this->composer(['show', 'typo3/cms-core', '--all', '--format=json', '--no-interaction'], 180);
    }

    public function clearCache(): CommandRequest
    {
        return $this->composer(['clear-cache', '--no-interaction'], 60);
    }

    public function validate(): CommandRequest
    {
        return $this->composer(['--version', '--no-interaction'], 30);
    }

    /**
     * @param list<string> $args composer sub-command + flags (no php/composer here)
     */
    private function composer(array $args, int $timeoutSeconds): CommandRequest
    {
        return CommandRequest::create(
            array_merge([$this->phpBinary, $this->composerBinary], $args),
            $this->projectDir,
            $timeoutSeconds,
        );
    }

    /**
     * Only allow real --ignore-platform-req=ext-* flags (produced by our own
     * platform checker) — never arbitrary strings.
     *
     * @param list<string> $flags
     * @return list<string>
     */
    private function sanitizeIgnoreFlags(array $flags): array
    {
        $safe = [];
        foreach ($flags as $flag) {
            if (\is_string($flag) && preg_match('/^--ignore-platform-req=ext-[a-z0-9_]+$/i', $flag) === 1) {
                $safe[] = $flag;
            }
        }

        return $safe;
    }
}
