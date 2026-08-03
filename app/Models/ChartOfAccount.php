<?php

namespace App\Models;

use App\Enums\AccountType;
use App\Enums\BalanceSide;
use App\Enums\SystemAccount;
use App\Models\Concerns\Auditable;
use App\Models\Concerns\BelongsToTenant;
use Database\Factories\ChartOfAccountFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

/**
 * One line of a workshop's chart of accounts.
 *
 * A ledger is a filtered view of journal entries by account — you never write
 * to a ledger directly. Get the journals right and every ledger is
 * automatically correct, which is why this table holds no balance column: a
 * stored running balance drifts, a derived one cannot.
 *
 * @property int $id
 * @property int $tenant_id
 * @property string $code
 * @property string $name
 * @property string|null $description
 * @property AccountType $type
 * @property SystemAccount|null $system_key
 * @property bool $is_active
 */
#[Fillable(['tenant_id', 'code', 'name', 'description', 'type', 'system_key', 'is_active'])]
class ChartOfAccount extends Model
{
    /** @use HasFactory<ChartOfAccountFactory> */
    use Auditable, BelongsToTenant, HasFactory;

    protected $table = 'chart_of_accounts';

    /**
     * `type` and `system_key` are absent because neither can change: the first
     * is refused by the service (reclassifying moves every entry ever posted to
     * a different financial statement), the second is set only by the
     * provisioner. A field that cannot move would only ever appear in a deletion
     * snapshot, and a system account is never deleted.
     *
     * @return array<int, string>
     */
    public function auditAttributes(): array
    {
        return ['code', 'name', 'description', 'is_active'];
    }

    /**
     * Both parts, because either alone is ambiguous on a trail: two workshops
     * rename accounts freely, and the code is what a reader matches against a
     * report.
     */
    public function auditLabel(): string
    {
        return trim($this->code.' · '.$this->name, ' ·');
    }

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'type' => AccountType::class,
            'system_key' => SystemAccount::class,
            'is_active' => 'boolean',
        ];
    }

    /**
     * A seeded account the engine depends on. Derived from system_key rather
     * than stored separately, so the two can never disagree.
     */
    public function isSystem(): bool
    {
        return $this->system_key !== null;
    }

    /**
     * Named `represents` rather than `is` — Eloquent's Model::is() compares
     * two model instances, and overriding it would break route-model binding
     * and relation comparisons everywhere.
     */
    public function represents(SystemAccount $account): bool
    {
        return $this->system_key === $account;
    }

    /**
     * The side that increases this account.
     */
    public function normalBalance(): BalanceSide
    {
        return $this->type->normalBalance();
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
     * @param  Builder<self>  $query
     * @return Builder<self>
     */
    public function scopeSystem(Builder $query): Builder
    {
        return $query->whereNotNull('system_key');
    }

    /**
     * @param  Builder<self>  $query
     * @return Builder<self>
     */
    public function scopeOfType(Builder $query, AccountType $type): Builder
    {
        return $query->where('type', $type->value);
    }

    public function getRouteKeyName(): string
    {
        return 'id';
    }
}
