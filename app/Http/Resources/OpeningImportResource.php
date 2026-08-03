<?php

namespace App\Http\Resources;

use App\Models\OpeningImport;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * The receipt for one go-live declaration.
 *
 * Every figure here is a copy of what the ledger already holds — see
 * {@see OpeningImport}. A client showing these is showing a history of
 * decisions, not a position, and the trial balance is what says whether the
 * position is right.
 *
 * @mixin OpeningImport
 */
class OpeningImportResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'filename' => $this->filename,
            'date' => $this->date->toDateString(),

            'rows' => $this->row_count,
            'imported' => $this->imported_count,
            // Named for what it means rather than for what happened: a row is
            // skipped because the thing it declares was already declared, which
            // is a fact about the books rather than about the file.
            'already_declared' => $this->skipped_count,

            'stock_value' => $this->stockValueMoney()->amount(),
            'receivable_total' => $this->receivableMoney()->amount(),
            'payable_total' => $this->payableMoney()->amount(),
            'other_total' => $this->otherMoney()->amount(),
            'declared_total' => $this->declaredMoney()->amount(),

            'items_created' => $this->items_created,
            'parties_created' => $this->parties_created,

            'created_by' => $this->whenLoaded('creator', fn () => $this->creator?->name),
            'created_at' => $this->created_at?->toIso8601String(),

            // Only where somebody asked for the detail: an import posts a
            // handful of transactions and a history list does not need them.
            'transactions' => $this->whenLoaded(
                'transactions',
                fn () => $this->transactions->map(fn ($transaction) => [
                    'id' => $transaction->id,
                    'date' => $transaction->date->toDateString(),
                    'total' => $transaction->totalMoney()->amount(),
                    'notes' => $transaction->notes,
                ])->all(),
            ),
        ];
    }
}
