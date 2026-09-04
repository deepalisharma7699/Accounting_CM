<?php

namespace App\Models;

use App\Models\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;

/**
 * One published invoice — the link a customer was given, and its lifetime.
 *
 * Deliberately *not* a property of the transaction. See the migration for why a
 * posted document cannot carry one, and why a revoked share is kept rather than
 * deleted.
 *
 * @property int $id
 * @property int $tenant_id
 * @property int $transaction_id
 * @property string $token
 * @property int|null $created_by
 * @property Carbon|null $revoked_at
 * @property int|null $revoked_by
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 */
#[Fillable(['tenant_id', 'transaction_id', 'token', 'created_by', 'revoked_at', 'revoked_by'])]
class InvoiceShare extends Model
{
    use BelongsToTenant;

    /**
     * How much randomness the link carries.
     *
     * Forty characters of {@see Str::random()}, which draws from a 62-character
     * alphabet through `random_bytes` — the same source a session id uses. This
     * is the whole of the link's security, so it is a constant here rather than
     * a literal at the call site: a shorter one issued by a second caller would
     * weaken every link in the product without changing anything visible.
     */
    public const TOKEN_LENGTH = 40;

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'revoked_at' => 'datetime',
        ];
    }

    /* ---------------------------------------------------------------------
     | Relations
     |-------------------------------------------------------------------- */

    /**
     * @return BelongsTo<Transaction, $this>
     */
    public function transaction(): BelongsTo
    {
        return $this->belongsTo(Transaction::class);
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function revoker(): BelongsTo
    {
        return $this->belongsTo(User::class, 'revoked_by');
    }

    /* ---------------------------------------------------------------------
     | Queries
     |-------------------------------------------------------------------- */

    /**
     * The links that still open. A revoked one stays on the table as history
     * and must never be resolved again — the customer was told it had stopped
     * working, and it has.
     *
     * @param  Builder<self>  $query
     * @return Builder<self>
     */
    public function scopeLive(Builder $query): Builder
    {
        return $query->whereNull('revoked_at');
    }

    public function isLive(): bool
    {
        return $this->revoked_at === null;
    }

    /**
     * Where the customer is sent.
     *
     * Absolute, because the whole point is that it survives being pasted into
     * WhatsApp — a relative path is not a link once it leaves the browser.
     */
    public function url(): string
    {
        return route('invoices.public', ['token' => $this->token]);
    }

    /**
     * A token nothing else has.
     *
     * Unique across every workshop, not merely within one, because it is
     * resolved before tenancy is known. The retry is not superstition: the
     * column is unique, so a collision would be an exception on INSERT rather
     * than a silently shared link, and one loop here costs nothing against odds
     * that already round to never.
     */
    public static function freshToken(): string
    {
        do {
            $token = Str::random(self::TOKEN_LENGTH);
        } while (self::withoutGlobalScopes()->where('token', $token)->exists());

        return $token;
    }
}
