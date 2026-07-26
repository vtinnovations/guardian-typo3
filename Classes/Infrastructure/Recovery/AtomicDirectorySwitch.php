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

use Vtinnovations\GuardianTypo3\Domain\Exception\GuardianException;

/**
 * Atomically swaps a live directory (e.g. vendor/) for a fully-built staged one,
 * NEVER deleting the live directory before the replacement is in place.
 *
 * The contract that makes the vendor incident impossible:
 *   1. The staged directory must already be complete and validated by the caller.
 *   2. `rename(live → old)` then `rename(staged → live)` — two atomic renames on
 *      the SAME filesystem. At no instant does the project lack a vendor/.
 *   3. The previous vendor is retained at `oldPath` until the whole recovery has
 *      succeeded; on any post-switch failure it is restored atomically.
 *
 * If atomic rename across the required paths is not possible (different mounts),
 * the switch REFUSES to run rather than falling back to a recursive overwrite —
 * the caller must surface a preflight error.
 */
final class AtomicDirectorySwitch
{
    /**
     * Whether `rename()` between two locations would be atomic (same device).
     * Compares the device id of each path's nearest existing ancestor.
     */
    public function canAtomicallyRename(string $a, string $b): bool
    {
        $devA = $this->deviceOf($a);
        $devB = $this->deviceOf($b);

        return $devA !== null && $devB !== null && $devA === $devB;
    }

    /**
     * Renames $live to $oldPath (preserving it) and $staged into $live.
     *
     * @throws GuardianException if the operation cannot be performed atomically
     */
    public function switchIn(string $live, string $staged, string $oldPath): void
    {
        if (!is_dir($staged)) {
            throw new GuardianException('Refusing to switch in a staged directory that does not exist.');
        }
        if (!$this->canAtomicallyRename($live, $staged) || !$this->canAtomicallyRename($live, $oldPath)) {
            throw new GuardianException('Atomic rename is not possible across these paths (different filesystems). Recovery blocked.');
        }
        if (file_exists($oldPath)) {
            throw new GuardianException('The retained previous-directory path already exists — refusing to overwrite it.');
        }

        // 1) preserve the live directory (only if it exists).
        if (is_dir($live)) {
            if (!@rename($live, $oldPath)) {
                throw new GuardianException('Could not move the live directory aside — no change was made.');
            }
        }
        // 2) move the staged directory into place.
        if (!@rename($staged, $live)) {
            // Undo step 1 immediately so the site keeps its original directory.
            if (is_dir($oldPath)) {
                @rename($oldPath, $live);
            }
            throw new GuardianException('Could not move the staged directory into place — original restored.');
        }
    }

    /**
     * Reverts a switch: moves the failed live directory to $failedPath and
     * restores $oldPath back to $live. Used by rollback.
     */
    public function revert(string $live, string $oldPath, string $failedPath): void
    {
        if (is_dir($live) && !file_exists($failedPath)) {
            @rename($live, $failedPath); // keep the broken tree for diagnosis
        }
        if (is_dir($oldPath)) {
            if (is_dir($live)) {
                // Could not move the failed tree away; do not clobber.
                throw new GuardianException('Could not revert the directory switch cleanly — manual intervention required.');
            }
            if (!@rename($oldPath, $live)) {
                throw new GuardianException('Could not restore the previous directory during rollback.');
            }
        }
    }

    /**
     * Deletes a retained directory (old-vendor / failed tree) — only ever called
     * after the entire recovery has succeeded.
     */
    public function discard(string $path): void
    {
        if (is_dir($path)) {
            $this->rmrf($path);
        }
    }

    private function deviceOf(string $path): ?int
    {
        $probe = $path;
        while ($probe !== '' && $probe !== '/' && !file_exists($probe)) {
            $parent = \dirname($probe);
            if ($parent === $probe) {
                break;
            }
            $probe = $parent;
        }
        $stat = @stat($probe);

        return \is_array($stat) ? (int) $stat['dev'] : null;
    }

    private function rmrf(string $dir): void
    {
        $handle = @opendir($dir);
        if ($handle === false) {
            return;
        }
        while (($name = readdir($handle)) !== false) {
            if ($name === '.' || $name === '..') {
                continue;
            }
            $path = $dir . '/' . $name;
            if (is_link($path) || !is_dir($path)) {
                @unlink($path);
            } else {
                $this->rmrf($path);
                @rmdir($path);
            }
        }
        closedir($handle);
        @rmdir($dir);
    }
}
