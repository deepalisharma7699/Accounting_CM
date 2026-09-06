<?php

namespace App\Repositories\Contracts;

use App\Models\Passkey;
use App\Models\User;
use Illuminate\Support\Collection;

interface PasskeyRepositoryInterface
{
    /**
     * @param  array<string, mixed>  $attributes
     */
    public function create(array $attributes): Passkey;

    /**
     * Look a credential up with no account in hand.
     *
     * This is the sign-in path: a discoverable passkey arrives naming only
     * itself, so the credential id is the whole of what identifies the account.
     * Deliberately unscoped by user for that reason — the credential id is
     * unique across the table, which is what makes the answer unambiguous.
     */
    public function findByCredentialId(string $credentialId): ?Passkey;

    /**
     * Every passkey a person has, newest first.
     *
     * @return Collection<int, Passkey>
     */
    public function forUser(User|int $user): Collection;

    /**
     * One of a person's passkeys, by id.
     *
     * Scoped by user rather than found and then checked, so a caller cannot
     * forget the check: an id belonging to somebody else is simply not found.
     */
    public function findForUser(User|int $user, int $id): ?Passkey;

    public function countForUser(User|int $user): int;

    /**
     * Record that a credential was just used to sign in, keeping the
     * authenticator's counter in step so a clone can be spotted.
     */
    public function recordUse(Passkey $passkey, int $signCount, ?string $ip): Passkey;

    public function rename(Passkey $passkey, string $label): Passkey;

    public function delete(Passkey $passkey): void;
}
