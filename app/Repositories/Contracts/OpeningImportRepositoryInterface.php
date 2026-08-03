<?php

namespace App\Repositories\Contracts;

use App\Models\OpeningImport;
use Illuminate\Support\Collection;

interface OpeningImportRepositoryInterface
{
    /**
     * @param  array<string, mixed>  $attributes
     */
    public function create(array $attributes): OpeningImport;

    public function findByFingerprint(string $fingerprint): ?OpeningImport;

    /**
     * The workshop's import history, newest first.
     *
     * Not paginated: a workshop goes live once, and an import list that ran to
     * more than a handful of rows would mean something had gone wrong that
     * paging would only make harder to see.
     *
     * @return Collection<int, OpeningImport>
     */
    public function history(int $limit = 20): Collection;

    /* ---------------------------------------------------------------------
     | What has already been declared
     |-------------------------------------------------------------------- */

    /**
     * Variants that already carry opening stock.
     *
     * The per-target duplicate guard, and the reason a re-import cannot double a
     * balance even when the file has been edited since. One query for the whole
     * plan rather than one per row.
     *
     * @param  array<int, int>  $variantIds
     * @return array<int, int>
     */
    public function variantsWithOpeningStock(array $variantIds): array;

    /**
     * Parties that already have an opening balance posted against them.
     *
     * @param  array<int, int>  $partyIds
     * @return array<int, int>
     */
    public function partiesWithOpeningBalance(array $partyIds): array;

    /**
     * Accounts that already carry an opening entry — the same guard again, for
     * the cash, bank and loan rows that name an account rather than a party.
     *
     * @param  array<int, int>  $accountIds
     * @return array<int, int>
     */
    public function accountsWithOpeningEntry(array $accountIds): array;

    /**
     * How many opening transactions the workshop has posted at all.
     *
     * What turns the go-live screen from "declare your opening balances" into
     * "here is what you declared".
     */
    public function openingTransactionCount(): int;
}
