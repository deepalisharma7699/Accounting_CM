<?php

namespace App\Exceptions\Workshop;

use App\Exceptions\ApiException;

/**
 * The refusals the Kind master makes.
 *
 * The same idea the catalogue's masters are built on, applied to the bench: **a
 * definition that something already depends on may be switched off, but not
 * removed.** A kind deleted out from under the jobs filed under it would leave
 * their `specs` bags describing themselves in terms nothing can resolve — and a
 * job card that cannot say what came through the door is the one record a
 * workshop actually needs.
 *
 * Every message says what is in the way and what to do instead, because the
 * remedy is never obvious from the refusal alone: "archive it" is a different
 * button from the one they just pressed.
 */
class JobKindException extends ApiException
{
    public static function kindHasJobs(int $id, string $name, int $count): self
    {
        return new self(
            message: sprintf(
                '"%s" cannot be deleted: %d %s booked in under it. Archive the kind instead — '.
                'it will stop appearing on the intake form and go on explaining the jobs already using it.',
                $name,
                $count,
                $count === 1 ? 'job is' : 'jobs are',
            ),
            status: 409,
            errorCode: 'JOB_KIND_IN_USE',
            details: ['job_kind_id' => $id, 'jobs' => $count],
        );
    }

    /**
     * The seeded rows. Renameable, archivable, never deletable — jobs already
     * booked in refer to what they mean.
     */
    public static function kindProtected(int $id, string $name): self
    {
        return new self(
            message: sprintf(
                '"%s" is one of the kinds the workshop was set up with and cannot be deleted. '.
                'Archive it instead if nothing of that sort comes in any more.',
                $name,
            ),
            status: 409,
            errorCode: 'JOB_KIND_PROTECTED',
            details: ['job_kind_id' => $id],
        );
    }

    public static function kindNameTaken(string $name): self
    {
        return new self(
            message: sprintf(
                'There is already a kind called "%s". Two rows of that name would split the bench\'s '.
                'own history in half and both halves would look right.',
                $name,
            ),
            status: 422,
            errorCode: 'JOB_KIND_NAME_TAKEN',
            details: ['field' => 'name'],
        );
    }

    public static function fieldKeyTaken(string $key): self
    {
        return new self(
            message: sprintf(
                'This kind already asks a question stored as "%s". Two definitions of one field '.
                'would leave the form picking one of them and silently ignoring the other.',
                $key,
            ),
            status: 422,
            errorCode: 'JOB_KIND_FIELD_KEY_TAKEN',
            details: ['field' => 'key'],
        );
    }

    /**
     * Deleting a field that jobs have already answered.
     *
     * The values sit in `workshop_jobs.specs` keyed by this field's `key`, and
     * the definition is the only thing that turns `{"hp": "7.5"}` back into
     * "7.5 HP". Removing it would not remove the answers, it would orphan them.
     */
    public static function fieldAnswered(int $id, string $label, int $count): self
    {
        return new self(
            message: sprintf(
                '"%s" cannot be deleted: %d %s already recorded an answer to it. Switch the field off '.
                'instead — it will stop being asked and go on explaining the answers already given.',
                $label,
                $count,
                $count === 1 ? 'job has' : 'jobs have',
            ),
            status: 409,
            errorCode: 'JOB_KIND_FIELD_ANSWERED',
            details: ['attribute_id' => $id, 'jobs' => $count],
        );
    }

    /**
     * Removing a choice that jobs have already been filed under.
     *
     * The job keeps the string it recorded either way — nothing rewrites a bag —
     * so the damage is quiet: the card goes on reading "Copper" while the list
     * no longer contains it, and the next person to edit that job loses the
     * value by opening the form. Refused with the choices named.
     *
     * @param  array<int, string>  $missing
     */
    public static function fieldOptionsStillUsed(int $id, string $label, array $missing): self
    {
        return new self(
            message: sprintf(
                '%s cannot be removed from "%s": %s already filed under %s. Rename the choice, or '.
                'leave it in the list — a job keeps what it recorded, and the form would drop it the '.
                'next time somebody opened that card.',
                count($missing) === 1 ? 'That choice' : 'Those choices',
                $label,
                count($missing) === 1 ? 'a job is' : 'jobs are',
                count($missing) === 1 ? 'it' : 'them',
            ),
            status: 409,
            errorCode: 'JOB_KIND_FIELD_OPTION_IN_USE',
            details: ['attribute_id' => $id, 'options' => array_values($missing)],
        );
    }
}
