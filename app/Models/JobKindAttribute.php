<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One field a job kind asks about the thing standing on the bench.
 *
 * The bench's half of {@see AttributeDefinition}. A workshop adds "Tank
 * capacity" to Cooler and the intake form grows a field — no migration, no API,
 * no component, no deployment, which is the same acceptance criterion the
 * catalogue module is built to.
 *
 * The values go into `workshop_jobs.specs`, keyed by `key`, exactly as a
 * product's go into `item_variants.attributes`. Write-once for the same reason.
 *
 * ## `is_required` is stored and deliberately not enforced
 *
 * On a product it means a motor with no HP is not identifiable by anybody
 * afterwards. On a bench it cannot mean that: a pump whose plate nobody could
 * read is already standing there, and a form that refused it is a form that got
 * a job card written on paper instead. So the flag is kept — it is what a
 * workshop said about its own question set, and switching a kind's fields
 * between the two sides should not lose it — and `pages/jobs.js` draws these
 * without required marks while {@see \App\Services\Workshop\JobService} asks
 * for none of them.
 *
 * @property int $job_kind_id
 */
#[Fillable([
    'tenant_id', 'job_kind_id', 'key', 'label', 'data_type', 'unit_code',
    'is_required', 'default_value', 'options', 'min_value', 'max_value',
    'help_text', 'display_order', 'is_active',
])]
class JobKindAttribute extends AttributeDefinition
{
    public function ownerKey(): string
    {
        return 'job_kind_id';
    }

    /**
     * @return BelongsTo<JobKind, $this>
     */
    public function owner(): BelongsTo
    {
        return $this->kind();
    }

    /**
     * @return BelongsTo<JobKind, $this>
     */
    public function kind(): BelongsTo
    {
        return $this->belongsTo(JobKind::class, 'job_kind_id');
    }
}
