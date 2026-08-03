<?php

namespace Tests\Fixtures;

use App\Models\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;

/**
 * A stand-in for the tenant-owned tables that Slice A onwards will add
 * (parties, items, transactions, journal entries).
 *
 * The BelongsToTenant trait is the single thing standing between two
 * workshops' books on MySQL, so it is proven here — against a real table, with
 * real queries — before anything valuable depends on it.
 *
 * @property int $id
 * @property int $tenant_id
 * @property string $label
 */
#[Fillable(['tenant_id', 'label'])]
class TenantScopedFixture extends Model
{
    use BelongsToTenant;

    protected $table = 'tenant_scoped_fixtures';

    public $timestamps = false;
}
