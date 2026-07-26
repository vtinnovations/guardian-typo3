<?php

declare(strict_types=1);

/*
 * This file is part of the "Guardian for TYPO3" extension for TYPO3 CMS.
 *
 * @author    V&T Innovations Team
 * @license   LGPL-3.0-or-later
 * @copyright V&T Innovations 2026 - 2028
 */

namespace Vtinnovations\GuardianTypo3\Domain\Job;

/**
 * Lifecycle states of a background job, with the legal transition graph encoded
 * on the enum itself. Ported from the audited Contao UpdateJob status constants
 * and hardened: transitions that were only implied by procedural code are made
 * explicit and enforceable here.
 */
enum JobStatus: string
{
    case Queued = 'queued';
    case Running = 'running';
    case Succeeded = 'succeeded';
    case Failed = 'failed';
    case Cancelled = 'cancelled';

    public static function fromString(string $value): self
    {
        return self::tryFrom($value) ?? self::Queued;
    }

    public function isTerminal(): bool
    {
        return match ($this) {
            self::Succeeded, self::Failed, self::Cancelled => true,
            default => false,
        };
    }

    /**
     * Whether a direct transition to $target is allowed from this state.
     */
    public function canTransitionTo(self $target): bool
    {
        return match ($this) {
            self::Queued => \in_array($target, [self::Running, self::Cancelled, self::Failed], true),
            self::Running => \in_array($target, [self::Succeeded, self::Failed, self::Cancelled], true),
            self::Succeeded, self::Failed, self::Cancelled => false,
        };
    }
}
