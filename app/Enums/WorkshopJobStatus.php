<?php

namespace App\Enums;

/**
 * Where a motor has got to between arriving on the bench and going home — M19.
 *
 * ## Why this is not `JobStatus`
 *
 * Because {@see JobStatus} is taken, by M14's background queue. The two are
 * genuinely different things — one is "has the upload finished being read", the
 * other is "has the customer's pump been rewound" — and the workshop one is the
 * one that needed the qualifier, since a *job* in this trade means the motor on
 * the bench and only a programmer would think it meant a queued closure.
 *
 * ## Why the transitions are declared
 *
 * A job's status is the one field on it that everybody reads and anybody can
 * write, and a free-for-all shows up as a motor that is `delivered` on Tuesday
 * and back to `received` on Wednesday because somebody picked the wrong option
 * from a dropdown. {@see canMoveTo()} states what may follow what, in one place,
 * the way {@see TransactionStatus} states that only a draft may be edited.
 *
 * The pipeline runs forward, with two deliberate exceptions:
 *
 *   * **Cancelled is reachable from anywhere unfinished.** A customer who
 *     changes their mind does so at whatever point they change it at, and a
 *     status machine that made them wait until the motor was ready would be
 *     asking the workshop to lie about what happened.
 *   * **Ready may go back to in progress.** It failed the test run. That is not
 *     an exception at all in a rewinding shop; it is a Tuesday.
 *
 * Everything else is forward only. Notably `delivered` is terminal: the motor is
 * with the customer, and whatever comes back next is a new job with its own
 * complaint — not this one reopened, which would silently rewrite how long the
 * first repair took.
 */
enum WorkshopJobStatus: string
{
    /**
     * On the bench, written up, nothing done yet. Every job starts here — there
     * is no way to create one in any other state, because the complaint and the
     * motor are what a job *is* and both are known the moment it arrives.
     */
    case Received = 'received';

    /** Opened up and being looked at. What is actually wrong is not yet known. */
    case Inspection = 'inspection';

    /**
     * Priced, and waiting for the customer to say yes.
     *
     * The estimate itself is a field on the job rather than a document in the
     * books — see `workshop_jobs.estimate_lines`. Nothing has been earned and
     * nothing has been promised, so there is nothing to post.
     */
    case Estimate = 'estimate';

    /** Being worked on. Parts added from here are what the bill will carry. */
    case InProgress = 'in_progress';

    /** Finished and tested, waiting to be collected. */
    case Ready = 'ready';

    /**
     * Gone home. Terminal.
     *
     * Note that this is not the same as billed, and neither implies the other: a
     * regular customer's motor goes out on Friday against an invoice raised at
     * the end of the month, and a job billed in advance still sits on the shelf
     * until somebody comes for it.
     */
    case Delivered = 'delivered';

    /**
     * Abandoned — the estimate was refused, or the motor was not worth
     * repairing. Terminal, and unbillable: see {@see isBillable()}.
     */
    case Cancelled = 'cancelled';

    public function label(): string
    {
        return match ($this) {
            self::Received => 'Received',
            self::Inspection => 'Under inspection',
            self::Estimate => 'Estimate given',
            self::InProgress => 'In progress',
            self::Ready => 'Ready',
            self::Delivered => 'Delivered',
            self::Cancelled => 'Cancelled',
        };
    }

    /**
     * The badge colour family, decided here rather than in each screen that
     * renders one — the brief's §38.
     *
     * The same four words {@see PaymentStatus::tone()} uses, deliberately: the
     * front end has one badge helper, and a second vocabulary of colour names
     * would mean it had two. They are named for what they *mean* rather than for
     * a colour, so a change of palette is one change.
     *
     * `warning` is the interesting one, and it is not "something is wrong" — it
     * is "somebody else has to move". A job sitting at `estimate` is waiting on
     * the customer, which is exactly the row an owner should be chasing at five
     * o'clock, and it is the same sense in which a partly-paid invoice is amber.
     */
    public function tone(): string
    {
        return match ($this) {
            // In flight. The workshop has it and is getting on with it.
            self::Received, self::Inspection, self::InProgress => 'info',
            // Waiting on the customer to say yes.
            self::Estimate => 'warning',
            // The work is done.
            self::Ready => 'success',
            // Closed, and nothing left to do about it.
            self::Delivered => 'neutral',
            self::Cancelled => 'danger',
        };
    }

    /**
     * What may legally follow this status.
     *
     * @return array<int, self>
     */
    public function nextStates(): array
    {
        return match ($this) {
            self::Received => [self::Inspection, self::Estimate, self::InProgress, self::Cancelled],
            self::Inspection => [self::Estimate, self::InProgress, self::Cancelled],
            self::Estimate => [self::InProgress, self::Cancelled],
            self::InProgress => [self::Ready, self::Cancelled],
            // Back to the bench, because it failed the test run — the one
            // backwards move in the pipeline, and the commonest thing that
            // happens to a rewound motor.
            self::Ready => [self::Delivered, self::InProgress, self::Cancelled],
            self::Delivered, self::Cancelled => [],
        };
    }

    public function canMoveTo(self $next): bool
    {
        return in_array($next, $this->nextStates(), true);
    }

    /**
     * Nothing further happens to a job in this state.
     */
    public function isFinished(): bool
    {
        return $this->nextStates() === [];
    }

    /**
     * Whether a bill may be raised from a job in this state.
     *
     * Work has to have started. A job sitting at `received` has had nothing done
     * to it, so an invoice against it would be charging for an intention — and
     * the parts on it are a plan rather than a record of what was fitted.
     *
     * `cancelled` is excluded and is the one that matters: a job nobody
     * authorised must not be able to produce an invoice, which is exactly what
     * the brief's scenario 10 is about. It stays excluded even though parts may
     * have been listed on it, because listing a part reserves nothing and moves
     * nothing — see the module doc.
     */
    public function isBillable(): bool
    {
        return in_array($this, [self::InProgress, self::Ready, self::Delivered], true);
    }

    /**
     * Whether a job in this state is still on the workshop's plate.
     *
     * What the dashboard's "jobs pending" counts, and what a worklist defaults
     * to — the two terminal states are the ones nobody has to do anything about.
     */
    public function isOpen(): bool
    {
        return ! $this->isFinished();
    }

    /**
     * @return array<int, string>
     */
    public static function values(): array
    {
        return array_column(self::cases(), 'value');
    }

    /**
     * The catalogue a client builds its filters and its pipeline control from, so
     * the legal moves are the server's answer rather than a copy in the browser
     * that drifts the day a state is added.
     *
     * @return array<int, array{value: string, label: string, tone: string, next: array<int, string>, is_open: bool, is_billable: bool}>
     */
    public static function catalogue(): array
    {
        return array_map(fn (self $status) => [
            'value' => $status->value,
            'label' => $status->label(),
            'tone' => $status->tone(),
            'next' => array_map(fn (self $next) => $next->value, $status->nextStates()),
            'is_open' => $status->isOpen(),
            'is_billable' => $status->isBillable(),
        ], self::cases());
    }
}
