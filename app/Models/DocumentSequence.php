<?php

namespace App\Models;

use App\Enums\DocumentSeries;
use App\Models\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;

/**
 * The counter behind one series of document numbers, for one financial year.
 *
 * Deliberately thin. Everything interesting about numbering — the lock, the
 * increment, what happens when two people post at once — lives in
 * {@see \App\Services\Accounting\DocumentNumberService} and its repository,
 * because it is about *how the row is read*, and a model cannot express that.
 *
 * Note the absence of a relation to `transactions`. A sequence does not own the
 * documents it numbered: it holds only the next number, and the numbers already
 * issued are on the transactions themselves. Anything else would be a second
 * copy of the same fact.
 *
 * @property int $id
 * @property int $tenant_id
 * @property DocumentSeries $series
 * @property string $financial_year
 * @property int $next
 */
#[Fillable(['tenant_id', 'series', 'financial_year', 'next'])]
class DocumentSequence extends Model
{
    use BelongsToTenant;

    /**
     * The first number every series issues.
     *
     * 1001 rather than 1, for two reasons that both matter to somebody holding
     * the paper: a workshop's first invoice should not look like a test, and the
     * number's width does not change under it at the hundredth bill — so a column
     * of invoice numbers stays aligned and sorts the way it reads.
     */
    public const FIRST = 1001;

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'series' => DocumentSeries::class,
            'next' => 'integer',
        ];
    }
}
