<?php

namespace App\Repositories\Contracts;

use App\Models\Party;
use App\Services\Accounting\PartyLedgerService;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Support\Collection;

/**
 * Every method here is tenant-scoped by the global scope on Party. Note what is
 * absent: nothing reads or writes a balance, because a party has none — see
 * {@see PartyLedgerService}.
 */
interface PartyRepositoryInterface
{
    public function findById(int $id): ?Party;

    public function nameExists(string $name, ?int $exceptId = null): bool;

    /**
     * The other parties filing under the same GSTIN. Not a uniqueness check —
     * branches of one business legitimately share one — but the workshop is
     * told, because far more often it means the party was entered twice.
     *
     * @return Collection<int, Party>
     */
    public function sharingGstin(string $gstin, ?int $exceptId = null): Collection;

    /**
     * Every party, for pickers. Small by nature — a workshop deals with
     * hundreds, not millions — so it is fetched whole rather than paginated.
     *
     * @return Collection<int, Party>
     */
    public function all(bool $activeOnly = false): Collection;

    /**
     * @param  array<string, mixed>  $attributes
     */
    public function create(array $attributes): Party;

    /**
     * @param  array<string, mixed>  $attributes
     */
    public function update(Party $party, array $attributes): Party;

    /**
     * Only ever a party nothing points at — {@see transactionCount()} is
     * checked first, and the foreign key refuses it regardless.
     */
    public function delete(Party $party): bool;

    /**
     * How many transactions name this party, drafts included. A draft has not
     * reached the ledger, but deleting the party out from under it would leave
     * a voucher that can never be posted.
     */
    public function transactionCount(int $partyId): int;

    /**
     * @param  array{search?: string|null, role?: string|null, is_active?: bool|null, has_gstin?: bool|null, sort?: string|null, direction?: string|null}  $filters
     * @return LengthAwarePaginator<int, Party>
     */
    public function paginate(array $filters, int $perPage): LengthAwarePaginator;

    /**
     * When each of these parties was last dealt with, in one query for the
     * whole page.
     *
     * Dates only — the amounts come from the ledger, which is the only thing
     * that computes money here. `transactions.total` is a listing convenience
     * and summing it would quietly disagree with the control accounts the
     * moment a bill was edited.
     *
     * Every party is present in the result, with nulls where there is nothing:
     * "never traded with" is an answer, and a caller should not have to tell it
     * apart from "not fetched".
     *
     * @param  array<int, int>  $partyIds
     * @return array<int, array{
     *     last_transaction_at: string|null,
     *     last_sale_at: string|null,
     *     last_purchase_at: string|null,
     *     last_payment_at: string|null,
     *     transaction_count: int
     * }>
     */
    public function activityFor(array $partyIds): array;
}
