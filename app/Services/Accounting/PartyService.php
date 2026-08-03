<?php

namespace App\Services\Accounting;

use App\Enums\PartyRole;
use App\Exceptions\Accounting\PartyInUseException;
use App\Exceptions\ConflictException;
use App\Exceptions\ResourceNotFoundException;
use App\Models\Party;
use App\Repositories\Contracts\PartyRepositoryInterface;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Log;

/**
 * Maintaining the list of who a workshop trades with.
 *
 * Nothing here posts anything and nothing here holds a balance — what a party
 * owes is {@see PartyLedgerService}'s answer, derived from the ledger on every
 * read. This class is only concerned with the record: its name, its roles, its
 * tax identity, and whether it may be removed.
 */
class PartyService
{
    public function __construct(
        private readonly PartyRepositoryInterface $parties,
    ) {}

    /* ---------------------------------------------------------------------
     | Reading
     |-------------------------------------------------------------------- */

    /**
     * @param  array<string, mixed>  $filters
     * @return LengthAwarePaginator<int, Party>
     */
    public function paginate(array $filters, int $perPage = 25): LengthAwarePaginator
    {
        return $this->parties->paginate($filters, $perPage);
    }

    /**
     * @return Collection<int, Party>
     */
    public function all(bool $activeOnly = false): Collection
    {
        return $this->parties->all($activeOnly);
    }

    public function find(int $id): Party
    {
        return $this->parties->findById($id)
            ?? throw new ResourceNotFoundException('Party', $id);
    }

    /**
     * When each of a page of parties was last dealt with.
     *
     * Dates, not money — what they owe is {@see PartyLedgerService}'s answer
     * and stays that way.
     *
     * @param  Collection<int, Party>|array<int, Party>  $parties
     * @return array<int, array{
     *     last_transaction_at: string|null,
     *     last_sale_at: string|null,
     *     last_purchase_at: string|null,
     *     last_payment_at: string|null,
     *     transaction_count: int
     * }>
     */
    public function activityFor(Collection|array $parties): array
    {
        return $this->parties->activityFor(
            collect($parties)->pluck('id')->map(fn ($id) => (int) $id)->all()
        );
    }

    /* ---------------------------------------------------------------------
     | Writing
     |-------------------------------------------------------------------- */

    /**
     * @param  array{name: string, roles: array<int, string>, gstin?: string|null, phone?: string|null, email?: string|null, address?: string|null, notes?: string|null}  $data
     */
    public function create(array $data): Party
    {
        $name = trim($data['name']);
        $roles = $this->normaliseRoles($data['roles'] ?? []);
        $gstin = $this->normaliseGstin($data['gstin'] ?? null);

        $this->assertNameAvailable($name);

        $party = $this->parties->create([
            'name' => $name,
            'roles' => $roles,
            'gstin' => $gstin,
            // Derived, never taken from the client: the state code decides
            // CGST+SGST versus IGST in M9, and a hand-supplied one that
            // disagreed with the GSTIN would compute the wrong tax on every
            // bill without ever looking wrong.
            'state_code' => $gstin === null ? null : substr($gstin, 0, 2),
            'phone' => $this->trimmed($data['phone'] ?? null),
            'email' => $this->trimmed($data['email'] ?? null),
            'address' => $this->trimmed($data['address'] ?? null),
            'notes' => $this->trimmed($data['notes'] ?? null),
            'is_active' => true,
        ]);

        Log::info('parties.created', ['party_id' => $party->id, 'roles' => $roles]);

        return $party;
    }

    /**
     * @param  array<string, mixed>  $data
     */
    public function update(int $id, array $data): Party
    {
        $party = $this->find($id);
        $attributes = [];

        if (array_key_exists('name', $data)) {
            $name = trim((string) $data['name']);

            if ($name !== $party->name) {
                $this->assertNameAvailable($name, $party->id);
                $attributes['name'] = $name;
            }
        }

        if (array_key_exists('roles', $data)) {
            // A role may be added or removed freely, including a removal that
            // leaves an outstanding position behind. That is safe *because* the
            // party ledger reads both control accounts regardless of roles —
            // see PartyLedgerService. Were it scoped to the roles on the
            // record, this edit would hide money.
            $attributes['roles'] = $this->normaliseRoles($data['roles']);
        }

        if (array_key_exists('gstin', $data)) {
            $gstin = $this->normaliseGstin($data['gstin']);

            $attributes['gstin'] = $gstin;
            $attributes['state_code'] = $gstin === null ? null : substr($gstin, 0, 2);
        }

        foreach (['phone', 'email', 'address', 'notes'] as $field) {
            if (array_key_exists($field, $data)) {
                $attributes[$field] = $this->trimmed($data[$field]);
            }
        }

        if (array_key_exists('is_active', $data)) {
            $attributes['is_active'] = (bool) $data['is_active'];
        }

        if ($attributes === []) {
            return $party;
        }

        $party = $this->parties->update($party, $attributes);

        Log::info('parties.updated', ['party_id' => $party->id, 'fields' => array_keys($attributes)]);

        return $party;
    }

    /**
     * Remove a party who was never traded with — a typo, a duplicate caught
     * early, a prospect who went elsewhere.
     *
     * Anything with transactions behind it is refused. That is not a
     * convenience check: `transactions.party_id` is restrictOnDelete, so the
     * database would refuse it too. This exists so the answer is an explanation
     * with a way forward rather than a foreign-key violation.
     */
    public function delete(int $id): void
    {
        $party = $this->find($id);
        $count = $this->parties->transactionCount($party->id);

        if ($count > 0) {
            throw PartyInUseException::hasTransactions($party->id, $party->name, $count);
        }

        $this->parties->delete($party);

        Log::info('parties.deleted', ['party_id' => $party->id]);
    }

    /* ---------------------------------------------------------------------
     | GSTIN
     |-------------------------------------------------------------------- */

    /**
     * The other parties already filing under this GSTIN.
     *
     * Deliberately a warning and not a rule. A business with branches files one
     * GSTIN across all of them, and a workshop dealing with two of those
     * branches has two parties, correctly. But the far commoner cause is the
     * same party entered twice — which splits one balance in half — so the
     * duplicate is put in front of the user at the moment they can still act on
     * it, rather than refused or ignored.
     *
     * @return Collection<int, Party>
     */
    public function othersSharingGstin(?string $gstin, ?int $exceptId = null): Collection
    {
        $gstin = $this->normaliseGstin($gstin);

        return $gstin === null
            ? new Collection
            : $this->parties->sharingGstin($gstin, $exceptId);
    }

    /* ---------------------------------------------------------------------
     | Invariants
     |-------------------------------------------------------------------- */

    /**
     * At least one role, each of them real, and no duplicates.
     *
     * A party with no role would appear in neither the customer list nor the
     * vendor list while still accumulating a balance — present in the books and
     * absent from every screen that would show it.
     *
     * @param  array<int, string>  $roles
     * @return array<int, string>
     */
    private function normaliseRoles(array $roles): array
    {
        $valid = [];

        foreach ($roles as $role) {
            $case = PartyRole::tryFrom((string) $role);

            if ($case !== null) {
                $valid[$case->value] = $case->value;
            }
        }

        if ($valid === []) {
            throw new ConflictException(
                'A party must be a customer, a vendor, or both.',
                'PARTY_ROLE_REQUIRED',
                ['field' => 'roles'],
            );
        }

        // Ordered by the enum rather than by input, so ["vendor","customer"]
        // and ["customer","vendor"] are stored identically and a JSON
        // comparison of two equivalent parties succeeds.
        return array_values(array_filter(
            PartyRole::values(),
            fn (string $role) => isset($valid[$role]),
        ));
    }

    private function normaliseGstin(?string $gstin): ?string
    {
        $gstin = strtoupper(trim((string) $gstin));

        return $gstin === '' ? null : $gstin;
    }

    private function trimmed(mixed $value): ?string
    {
        $value = trim((string) ($value ?? ''));

        return $value === '' ? null : $value;
    }

    private function assertNameAvailable(string $name, ?int $exceptId = null): void
    {
        if (! $this->parties->nameExists($name, $exceptId)) {
            return;
        }

        throw new ConflictException(
            "A party named \"{$name}\" already exists. Two records with one name split a single balance in ".
            'two, so give this one something that tells them apart — the branch, or the town.',
            'PARTY_NAME_TAKEN',
            ['field' => 'name'],
        );
    }
}
