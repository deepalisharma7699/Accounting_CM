<?php

namespace App\Repositories\Eloquent;

use App\Enums\StockMovementType;
use App\Enums\TransactionType;
use App\Models\JournalEntry;
use App\Models\OpeningImport;
use App\Models\StockMovement;
use App\Models\Transaction;
use App\Repositories\Contracts\OpeningImportRepositoryInterface;
use Illuminate\Support\Collection;

/**
 * Reading and writing the go-live record.
 *
 * Every query here is Eloquent rather than raw, for the reason every repository
 * in this application is: a raw builder bypasses the tenant scope, which on
 * MySQL is the whole isolation boundary — and this is the one module whose
 * queries deliberately reach across three tables at once.
 */
class EloquentOpeningImportRepository implements OpeningImportRepositoryInterface
{
    public function create(array $attributes): OpeningImport
    {
        return OpeningImport::create($attributes);
    }

    public function findByFingerprint(string $fingerprint): ?OpeningImport
    {
        return OpeningImport::where('fingerprint', $fingerprint)->first();
    }

    public function history(int $limit = 20): Collection
    {
        return OpeningImport::query()
            ->with('creator:id,name')
            ->newestFirst()
            ->limit($limit)
            ->get();
    }

    /* ---------------------------------------------------------------------
     | What has already been declared
     |-------------------------------------------------------------------- */

    public function variantsWithOpeningStock(array $variantIds): array
    {
        if ($variantIds === []) {
            return [];
        }

        // On the movement's own `type`, not on the transaction's. They agree
        // today, but the movement is the row that would be doubled and it is the
        // one worth asking — and a reversal of an opening balance is typed
        // `adjust`, so a workshop that reversed a bad import can legitimately
        // declare again.
        return StockMovement::query()
            ->whereIn('variant_id', $variantIds)
            ->where('type', StockMovementType::Opening->value)
            ->distinct()
            ->pluck('variant_id')
            ->map(fn ($id) => (int) $id)
            ->all();
    }

    public function partiesWithOpeningBalance(array $partyIds): array
    {
        if ($partyIds === []) {
            return [];
        }

        return Transaction::query()
            ->whereIn('party_id', $partyIds)
            ->where('type', TransactionType::Opening->value)
            // Drafts count. A parked opening balance is one somebody is still
            // working on, and importing a second one beside it would leave two
            // declarations of the same fact for them to choose between.
            ->distinct()
            ->pluck('party_id')
            ->map(fn ($id) => (int) $id)
            ->all();
    }

    public function accountsWithOpeningEntry(array $accountIds): array
    {
        if ($accountIds === []) {
            return [];
        }

        return JournalEntry::query()
            ->whereIn('account_id', $accountIds)
            ->whereHas('transaction', fn ($query) => $query->where('type', TransactionType::Opening->value))
            ->distinct()
            ->pluck('account_id')
            ->map(fn ($id) => (int) $id)
            ->all();
    }

    public function openingTransactionCount(): int
    {
        return Transaction::query()
            ->where('type', TransactionType::Opening->value)
            ->count();
    }
}
