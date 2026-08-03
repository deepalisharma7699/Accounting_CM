<?php

namespace App\Repositories\Eloquent;

use App\Enums\PartyRole;
use App\Enums\TransactionStatus;
use App\Enums\TransactionType;
use App\Models\Party;
use App\Models\Transaction;
use App\Repositories\Contracts\PartyRepositoryInterface;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Support\Collection;

class EloquentPartyRepository implements PartyRepositoryInterface
{
    /**
     * Columns a client may sort by, so nothing user-supplied reaches ORDER BY.
     *
     * @var array<int, string>
     */
    private const SORTABLE = ['name', 'created_at'];

    /**
     * Which transaction types answer for each "last …" date.
     *
     * An expense counts as a purchase: money spent with a supplier is a
     * dealing with them, and a workshop that buys its power tools on an
     * expense voucher would otherwise look as though it had not bought from
     * that vendor at all.
     *
     * @var array<string, array<int, string>>
     */
    private const ACTIVITY_KINDS = [
        'last_sale_at' => [TransactionType::Sale->value],
        'last_purchase_at' => [TransactionType::Purchase->value, TransactionType::Expense->value],
        'last_payment_at' => [TransactionType::Receipt->value, TransactionType::Payment->value],
    ];

    /**
     * @var array{last_transaction_at: null, last_sale_at: null, last_purchase_at: null, last_payment_at: null, transaction_count: int}
     */
    private const EMPTY_ACTIVITY = [
        'last_transaction_at' => null,
        'last_sale_at' => null,
        'last_purchase_at' => null,
        'last_payment_at' => null,
        'transaction_count' => 0,
    ];

    public function findById(int $id): ?Party
    {
        return Party::find($id);
    }

    public function nameExists(string $name, ?int $exceptId = null): bool
    {
        return Party::where('name', $name)
            ->when($exceptId !== null, fn ($query) => $query->whereKeyNot($exceptId))
            ->exists();
    }

    public function sharingGstin(string $gstin, ?int $exceptId = null): Collection
    {
        return Party::where('gstin', strtoupper($gstin))
            ->when($exceptId !== null, fn ($query) => $query->whereKeyNot($exceptId))
            ->orderBy('name')
            ->get();
    }

    public function all(bool $activeOnly = false): Collection
    {
        return Party::query()
            ->when($activeOnly, fn ($query) => $query->active())
            ->orderBy('name')
            ->get();
    }

    public function create(array $attributes): Party
    {
        return Party::create($attributes);
    }

    public function update(Party $party, array $attributes): Party
    {
        $party->fill($attributes)->save();

        return $party->refresh();
    }

    public function delete(Party $party): bool
    {
        return (bool) $party->delete();
    }

    public function transactionCount(int $partyId): int
    {
        return Transaction::where('party_id', $partyId)->count();
    }

    /**
     * The latest date of each kind of dealing, for a whole page of parties.
     *
     * Grouped by type as well as by party so that one pass answers all four
     * questions: a customer screen wants the last sale, a vendor screen the
     * last purchase, and both want the last settlement. Doing it as four
     * correlated subqueries would be four scans of the same rows.
     *
     * Posted only. A draft is a voucher somebody is still writing, and dating
     * the relationship from one would say the workshop last dealt with this
     * party on a day nothing actually happened.
     */
    public function activityFor(array $partyIds): array
    {
        $ids = array_values(array_unique(array_map('intval', $partyIds)));

        $activity = array_fill_keys($ids, self::EMPTY_ACTIVITY);

        if ($ids === []) {
            return $activity;
        }

        $rows = Transaction::query()
            ->whereIn('party_id', $ids)
            ->where('status', TransactionStatus::Posted->value)
            ->groupBy('party_id', 'type')
            ->selectRaw('party_id, type, MAX(date) as last_date, COUNT(*) as entries')
            ->get();

        foreach ($rows as $row) {
            $partyId = (int) $row->party_id;
            // `type` is cast to the enum on the model, and `date` is not — the
            // aggregate is aliased, so it arrives as the raw `Y-m-d` string the
            // column holds. Both are read for what they are rather than coerced.
            $type = $row->type instanceof TransactionType ? $row->type->value : (string) $row->type;
            $date = $row->last_date === null ? null : (string) $row->last_date;

            $current = $activity[$partyId];

            $current['transaction_count'] += (int) $row->entries;
            $current['last_transaction_at'] = self::later($current['last_transaction_at'], $date);

            foreach (self::ACTIVITY_KINDS as $key => $types) {
                if (in_array($type, $types, true)) {
                    $current[$key] = self::later($current[$key], $date);
                }
            }

            $activity[$partyId] = $current;
        }

        return $activity;
    }

    /**
     * Dates are `Y-m-d` strings straight from the column, so the later of two
     * is the greater of two — no parsing, and no timezone to get wrong.
     */
    private static function later(?string $left, ?string $right): ?string
    {
        if ($left === null) {
            return $right;
        }

        if ($right === null) {
            return $left;
        }

        return $right > $left ? $right : $left;
    }

    public function paginate(array $filters, int $perPage): LengthAwarePaginator
    {
        $sort = in_array($filters['sort'] ?? null, self::SORTABLE, true)
            ? $filters['sort']
            : 'name';

        $direction = strtolower((string) ($filters['direction'] ?? 'asc')) === 'desc' ? 'desc' : 'asc';

        return Party::query()
            ->when(
                filled($filters['search'] ?? null),
                fn ($query) => $query->where(function ($query) use ($filters) {
                    $term = '%'.$filters['search'].'%';

                    $query->where('name', 'like', $term)
                        ->orWhere('phone', 'like', $term)
                        ->orWhere('email', 'like', $term)
                        ->orWhere('gstin', 'like', $term);
                })
            )
            // Role membership, not role equality: filtering for customers must
            // still return the counterparty who is also a vendor, or the one
            // record that models both sides of a relationship would disappear
            // from both lists.
            ->when(
                filled($filters['role'] ?? null) && PartyRole::tryFrom((string) $filters['role']) !== null,
                fn ($query) => $query->withRole(PartyRole::from((string) $filters['role']))
            )
            ->when(
                ($filters['is_active'] ?? null) !== null,
                fn ($query) => $query->where('is_active', $filters['is_active'])
            )
            ->when(
                ($filters['has_gstin'] ?? null) !== null,
                fn ($query) => $filters['has_gstin']
                    ? $query->whereNotNull('gstin')
                    : $query->whereNull('gstin')
            )
            ->orderBy($sort, $direction)
            // A stable tiebreaker: names are unique per workshop, but a sort by
            // created_at is not, and without this a page boundary can repeat or
            // skip a row.
            ->orderBy('id')
            ->paginate($perPage)
            ->withQueryString();
    }
}
