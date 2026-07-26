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

use Vtinnovations\GuardianTypo3\Domain\Exception\GuardianException;

/**
 * Immutable representation of a single background job.
 *
 * Redesign of the audited Contao UpdateJob: the mutable public-property model is
 * replaced with an immutable value object whose transitions go through guarded
 * "with…" methods that enforce {@see JobStatus::canTransitionTo()}. This makes
 * the state machine explicit and unit-testable, and prevents cleanup code from
 * silently mis-recording a job's outcome (a real footgun in the original, which
 * had to pin the "failed step" defensively).
 *
 * No actual step is executed here — that is the job runner's concern in a later
 * phase. This object only models identity, lifecycle and metadata.
 */
final class Job
{
    /**
     * @param list<string>         $steps
     * @param array<string, mixed> $options
     */
    public function __construct(
        public readonly string $id,
        public readonly JobType $type,
        public readonly JobStatus $status,
        public readonly array $steps,
        public readonly ?string $currentStep = null,
        public readonly ?string $failedStep = null,
        public readonly ?string $error = null,
        public readonly ?\DateTimeImmutable $createdAt = null,
        public readonly ?\DateTimeImmutable $startedAt = null,
        public readonly ?\DateTimeImmutable $finishedAt = null,
        public readonly array $options = [],
    ) {
    }

    /**
     * @param list<string>         $steps
     * @param array<string, mixed> $options
     */
    public static function queue(string $id, JobType $type, array $steps, \DateTimeImmutable $createdAt, array $options = []): self
    {
        return new self(
            id: $id,
            type: $type,
            status: JobStatus::Queued,
            steps: array_values($steps),
            createdAt: $createdAt,
            options: $options,
        );
    }

    public function isFinished(): bool
    {
        return $this->status->isTerminal();
    }

    public function start(string $firstStep, \DateTimeImmutable $at): self
    {
        return $this->transition(JobStatus::Running, [
            'currentStep' => $firstStep,
            'startedAt' => $at,
        ]);
    }

    public function advanceTo(string $step): self
    {
        if ($this->status !== JobStatus::Running) {
            throw new GuardianException(sprintf(
                'Cannot advance job %s to step "%s": job is %s, not running.',
                $this->id,
                $step,
                $this->status->value
            ));
        }

        return $this->copyWith(['currentStep' => $step]);
    }

    public function succeed(\DateTimeImmutable $at): self
    {
        return $this->transition(JobStatus::Succeeded, [
            'currentStep' => null,
            'finishedAt' => $at,
        ]);
    }

    public function fail(string $failedStep, string $error, \DateTimeImmutable $at): self
    {
        return $this->transition(JobStatus::Failed, [
            'failedStep' => $failedStep,
            'error' => $error,
            'finishedAt' => $at,
        ]);
    }

    public function cancel(string $reason, \DateTimeImmutable $at): self
    {
        return $this->transition(JobStatus::Cancelled, [
            'error' => $reason,
            'finishedAt' => $at,
        ]);
    }

    /**
     * @param array<string, mixed> $data
     */
    public static function fromArray(array $data): self
    {
        return new self(
            id: (string) ($data['id'] ?? ''),
            type: JobType::fromString((string) ($data['type'] ?? 'dry_run')),
            status: JobStatus::fromString((string) ($data['status'] ?? 'queued')),
            steps: array_values(array_map('strval', (array) ($data['steps'] ?? []))),
            currentStep: self::nullableString($data['current_step'] ?? null),
            failedStep: self::nullableString($data['failed_step'] ?? null),
            error: self::nullableString($data['error'] ?? null),
            createdAt: self::parseDate($data['created_at'] ?? null),
            startedAt: self::parseDate($data['started_at'] ?? null),
            finishedAt: self::parseDate($data['finished_at'] ?? null),
            options: \is_array($data['options'] ?? null) ? $data['options'] : [],
        );
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'id' => $this->id,
            'type' => $this->type->value,
            'status' => $this->status->value,
            'steps' => $this->steps,
            'current_step' => $this->currentStep,
            'failed_step' => $this->failedStep,
            'error' => $this->error,
            'created_at' => $this->createdAt?->format(\DATE_ATOM),
            'started_at' => $this->startedAt?->format(\DATE_ATOM),
            'finished_at' => $this->finishedAt?->format(\DATE_ATOM),
            'options' => $this->options,
        ];
    }

    /**
     * @param array<string, mixed> $changes
     */
    private function transition(JobStatus $target, array $changes): self
    {
        if (!$this->status->canTransitionTo($target)) {
            throw new GuardianException(sprintf(
                'Illegal job transition for %s: %s → %s.',
                $this->id,
                $this->status->value,
                $target->value
            ));
        }

        return $this->copyWith(['status' => $target] + $changes);
    }

    /**
     * @param array<string, mixed> $changes
     */
    private function copyWith(array $changes): self
    {
        return new self(
            id: $this->id,
            type: $this->type,
            status: $changes['status'] ?? $this->status,
            steps: $this->steps,
            currentStep: \array_key_exists('currentStep', $changes) ? $changes['currentStep'] : $this->currentStep,
            failedStep: \array_key_exists('failedStep', $changes) ? $changes['failedStep'] : $this->failedStep,
            error: \array_key_exists('error', $changes) ? $changes['error'] : $this->error,
            createdAt: $this->createdAt,
            startedAt: \array_key_exists('startedAt', $changes) ? $changes['startedAt'] : $this->startedAt,
            finishedAt: \array_key_exists('finishedAt', $changes) ? $changes['finishedAt'] : $this->finishedAt,
            options: $this->options,
        );
    }

    private static function nullableString(mixed $value): ?string
    {
        return $value === null ? null : (string) $value;
    }

    private static function parseDate(mixed $value): ?\DateTimeImmutable
    {
        if (!\is_string($value) || $value === '') {
            return null;
        }

        try {
            return new \DateTimeImmutable($value);
        } catch (\Throwable) {
            return null;
        }
    }
}
