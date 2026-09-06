<?php

namespace App\Repositories\Eloquent;

use App\Models\Passkey;
use App\Models\User;
use App\Repositories\Contracts\PasskeyRepositoryInterface;
use Illuminate\Support\Collection;

class EloquentPasskeyRepository implements PasskeyRepositoryInterface
{
    public function create(array $attributes): Passkey
    {
        return Passkey::create($attributes);
    }

    public function findByCredentialId(string $credentialId): ?Passkey
    {
        return Passkey::where('credential_id', $credentialId)->first();
    }

    public function forUser(User|int $user): Collection
    {
        return Passkey::where('user_id', $this->key($user))
            ->orderByDesc('created_at')
            ->get();
    }

    public function findForUser(User|int $user, int $id): ?Passkey
    {
        return Passkey::where('user_id', $this->key($user))
            ->whereKey($id)
            ->first();
    }

    public function countForUser(User|int $user): int
    {
        return Passkey::where('user_id', $this->key($user))->count();
    }

    public function recordUse(Passkey $passkey, int $signCount, ?string $ip): Passkey
    {
        /*
        | forceFill rather than update(): none of these three is fillable, and
        | none should be — they move on their own, on every sign-in, and are
        | nobody's input.
        */
        $passkey->forceFill([
            'sign_count' => $signCount,
            'last_used_at' => now(),
            'last_used_ip' => $ip,
        ])->save();

        return $passkey;
    }

    public function rename(Passkey $passkey, string $label): Passkey
    {
        $passkey->update(['label' => $label]);

        return $passkey;
    }

    public function delete(Passkey $passkey): void
    {
        $passkey->delete();
    }

    private function key(User|int $user): int
    {
        return $user instanceof User ? (int) $user->getKey() : $user;
    }
}
