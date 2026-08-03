<?php

namespace App\Models;

use App\Enums\PartyRole;
use App\Models\Concerns\Auditable;
use App\Models\Concerns\BelongsToTenant;
use App\Services\Accounting\PartyLedgerService;
use Database\Factories\PartyFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * A customer, a supplier, or — commonly — one counterparty who is both.
 *
 * **There is no balance column here, by design.** What this party owes, or is
 * owed, is derived on every read from the journal entries their transactions
 * produced. See {@see PartyLedgerService}. A stored outstanding is the same
 * mistake as a stored account balance: it agrees with the ledger right up until
 * one of them is written without the other.
 *
 * Roles are a set rather than a column, so the shop that sells you scrap and
 * buys back a rewound motor is one record with one combined ledger — not two
 * records whose balances are never netted against each other.
 *
 * @property int $id
 * @property int $tenant_id
 * @property string $name
 * @property array<int, string> $roles
 * @property string|null $gstin
 * @property string|null $state_code
 * @property string|null $phone
 * @property string|null $email
 * @property string|null $address
 * @property string|null $notes
 * @property bool $is_active
 */
#[Fillable([
    'tenant_id', 'name', 'roles', 'gstin', 'state_code',
    'phone', 'email', 'address', 'notes', 'is_active',
])]
class Party extends Model
{
    /** @use HasFactory<PartyFactory> */
    use Auditable, BelongsToTenant, HasFactory;

    /**
     * Everything a workshop can edit, and the GSTIN above all: it decides
     * whether an invoice is intra- or inter-state, so changing it silently moves
     * which columns of a tax return the party's business lands in. `roles` is
     * here for a related reason — dropping the "vendor" tag stops a payment
     * being accepted against them, and the refusal is baffling without the trail.
     *
     * @return array<int, string>
     */
    public function auditAttributes(): array
    {
        return [
            'name', 'roles', 'gstin', 'state_code',
            'phone', 'email', 'address', 'notes', 'is_active',
        ];
    }

    public function auditLabel(): string
    {
        return $this->name;
    }

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'roles' => 'array',
            'is_active' => 'boolean',
        ];
    }

    /* ---------------------------------------------------------------------
     | Relations
     |-------------------------------------------------------------------- */

    /**
     * Every transaction that named this party — drafts included, which is why
     * deleting a party has to consider more than the ledger.
     *
     * @return HasMany<Transaction, $this>
     */
    public function transactions(): HasMany
    {
        return $this->hasMany(Transaction::class);
    }

    /* ---------------------------------------------------------------------
     | Roles
     |-------------------------------------------------------------------- */

    /**
     * @return array<int, PartyRole>
     */
    public function roleSet(): array
    {
        return array_values(array_filter(array_map(
            static fn (string $role) => PartyRole::tryFrom($role),
            $this->roles ?? [],
        )));
    }

    public function hasRole(PartyRole $role): bool
    {
        return in_array($role->value, $this->roles ?? [], true);
    }

    public function isCustomer(): bool
    {
        return $this->hasRole(PartyRole::Customer);
    }

    public function isVendor(): bool
    {
        return $this->hasRole(PartyRole::Vendor);
    }

    /* ---------------------------------------------------------------------
     | Scopes
     |-------------------------------------------------------------------- */

    /**
     * @param  Builder<self>  $query
     * @return Builder<self>
     */
    public function scopeActive(Builder $query): Builder
    {
        return $query->where('is_active', true);
    }

    /**
     * Everyone holding a role — including those who hold the other one too,
     * which is the point of storing roles as a set.
     *
     * @param  Builder<self>  $query
     * @return Builder<self>
     */
    public function scopeWithRole(Builder $query, PartyRole $role): Builder
    {
        return $query->whereJsonContains('roles', $role->value);
    }

    public function getRouteKeyName(): string
    {
        return 'id';
    }
}
