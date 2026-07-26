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

use Vtinnovations\GuardianTypo3\Application\Contract\ProjectEnvironmentInterface;
use Vtinnovations\GuardianTypo3\Domain\Exception\GuardianException;
use Vtinnovations\GuardianTypo3\Domain\Recovery\PanelFilename;

/**
 * Deploys / removes the standalone recovery panel entrypoint in the public web
 * root.
 *
 * Deployment is an explicit admin action (never automatic on boot): the panel is
 * a powerful, framework-free restore tool, so its presence in the webroot is a
 * real attack surface. Key safety properties:
 *
 *  - The deployed file carries an ownership SIGNATURE marker so Guardian can
 *    recognise files it manages. Guardian NEVER deletes a same-named file that
 *    lacks the marker — an operator's own file is left untouched.
 *  - Deployment is atomic: the file is written to a sibling temp file and then
 *    renamed over the target, so a reader never observes a half-written panel.
 *  - Changing the filename deploys the NEW entrypoint first and only removes the
 *    previous managed entrypoint AFTER the new one is in place.
 *  - No secret (token / license key) is ever written into the file.
 */
final class RecoveryPanelDeployer
{
    private const TEMPLATE = 'Resources/Private/Recovery/_guardian-recovery.php';

    /** Marker that identifies a file as Guardian-managed. Must exist in the template. */
    public const SIGNATURE = 'GUARDIAN-RECOVERY-PANEL:MANAGED-ENTRYPOINT';

    private readonly string $extensionRoot;

    public function __construct(
        private readonly ProjectEnvironmentInterface $environment,
        private readonly RecoveryPanelConfigStore $config,
        ?string $extensionRoot = null,
    ) {
        $this->extensionRoot = $extensionRoot ?? \dirname(__DIR__, 3);
    }

    public function filename(): string
    {
        return $this->config->filename();
    }

    public function isDeployed(): bool
    {
        return $this->isManaged($this->pathFor($this->filename()));
    }

    public function deployedPath(): ?string
    {
        $path = $this->pathFor($this->filename());

        return $this->isManaged($path) ? $path : null;
    }

    /**
     * Deploys (or refreshes) the entrypoint for the given filename and removes a
     * previously-managed entrypoint if the filename changed.
     *
     * @throws GuardianException
     */
    public function deploy(?string $previousFilename = null): void
    {
        $filename = $this->filename();
        $source = $this->extensionRoot . '/' . self::TEMPLATE;
        if (!is_file($source)) {
            throw new GuardianException('Recovery panel template is missing from the extension.');
        }
        $contents = (string) @file_get_contents($source);
        if ($contents === '' || !str_contains($contents, self::SIGNATURE)) {
            throw new GuardianException('Recovery panel template is invalid (missing ownership marker).');
        }

        $public = rtrim($this->environment->publicPath(), '/');
        if (!is_dir($public) || !is_writable($public)) {
            throw new GuardianException('The public web root is not writable.');
        }

        $target = $this->pathFor($filename);
        // Never overwrite a same-named file we do not own.
        if (is_file($target) && !$this->isManaged($target)) {
            throw new GuardianException('A different, unmanaged file already exists at that location.');
        }

        $this->atomicWrite($target, $contents);

        // Remove the previous managed entrypoint AFTER the new one is in place.
        if ($previousFilename !== null && $previousFilename !== $filename && PanelFilename::isValid($previousFilename)) {
            $this->removeManaged($this->pathFor($previousFilename));
        }
    }

    /**
     * Removes the currently-configured managed entrypoint. Safe to call when the
     * panel is not deployed; never touches an unmanaged file.
     */
    public function remove(): void
    {
        $this->removeManaged($this->pathFor($this->filename()));
    }

    /**
     * True only for a real file that carries Guardian's ownership marker.
     */
    public function isManaged(string $path): bool
    {
        if (!is_file($path)) {
            return false;
        }
        // Read only the head of the file — the marker sits in the banner.
        $head = (string) @file_get_contents($path, false, null, 0, 4096);

        return str_contains($head, self::SIGNATURE);
    }

    private function removeManaged(string $path): void
    {
        if ($this->isManaged($path)) {
            @unlink($path);
        }
    }

    private function atomicWrite(string $target, string $contents): void
    {
        $tmp = $target . '.' . bin2hex(random_bytes(4)) . '.tmp';
        if (@file_put_contents($tmp, $contents, \LOCK_EX) === false) {
            throw new GuardianException('Could not stage the recovery panel.');
        }
        @chmod($tmp, 0o644);
        if (!@rename($tmp, $target)) {
            @unlink($tmp);
            throw new GuardianException('Could not deploy the recovery panel.');
        }
    }

    private function pathFor(string $filename): string
    {
        // Defensive: only ever operate on a validated bare basename in public/.
        $safe = PanelFilename::fromString($filename)->value;

        return rtrim($this->environment->publicPath(), '/') . '/' . $safe;
    }
}
