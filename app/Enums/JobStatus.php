<?php

namespace App\Enums;

/**
 * Where a piece of background work has got to — M14.
 *
 * Four states and no `cancelled`. Nothing in Phase 1 can be cancelled: the work
 * a workshop queues is seconds long, and a cancel button that races the worker
 * would sometimes report a cancellation of something that had already finished.
 * A state the application cannot honestly reach is a state that should not be in
 * the enum — the same reason {@see PermissionAction} has no grants for writes
 * that cannot happen.
 */
enum JobStatus: string
{
    /** Handed to the queue. Nothing has picked it up yet. */
    case Queued = 'queued';

    case Running = 'running';
    case Succeeded = 'succeeded';

    /**
     * Every attempt was used up. Distinct from a *failing* attempt: a job that
     * threw and will be retried is back to `queued`, because from the caller's
     * point of view it is still going to happen.
     */
    case Failed = 'failed';

    public function label(): string
    {
        return match ($this) {
            self::Queued => 'Waiting',
            self::Running => 'In progress',
            self::Succeeded => 'Done',
            self::Failed => 'Failed',
        };
    }

    /**
     * Whether there is any point asking again.
     *
     * The client polls on this rather than on a list of statuses of its own, so
     * adding a state later does not leave every screen polling for ever.
     */
    public function isSettled(): bool
    {
        return in_array($this, [self::Succeeded, self::Failed], true);
    }

    /**
     * @return array<int, string>
     */
    public static function values(): array
    {
        return array_column(self::cases(), 'value');
    }

    /**
     * @return array<int, array{value: string, label: string}>
     */
    public static function catalogue(): array
    {
        return array_map(
            fn (self $status) => ['value' => $status->value, 'label' => $status->label()],
            self::cases(),
        );
    }
}
