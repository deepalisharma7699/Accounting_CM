<?php

namespace App\Services\Workshop;

use App\Models\JobKind;
use App\Models\Tenant;
use App\Support\Catalogue\AttributeFieldShape;
use App\Support\Tenancy\TenantContext;
use Illuminate\Support\Facades\DB;

/**
 * Gives a workshop the kinds of thing it takes in.
 *
 * The bench's counterpart to {@see \App\Services\Inventory\CatalogueProvisioner},
 * and it runs in the same breath and for the same kind of reason: a workshop
 * whose intake form opens on an empty Kind dropdown cannot describe what came
 * through the door, and the first thing anybody does with this product is book
 * something in.
 *
 * Idempotent by name. Re-running adds what is missing and leaves alone what a
 * workshop has renamed or switched off — so this is safe to call on an existing
 * installation, which is how the workshops that predate the Kind master get
 * their list.
 */
class JobKindProvisioner
{
    public function __construct(
        private readonly TenantContext $context,
        private readonly AttributeFieldShape $shape,
    ) {}

    /**
     * Create any seeded kind this workshop does not already have.
     *
     * @return array{kinds: int, fields: int}
     */
    public function seedFor(Tenant|int $tenant): array
    {
        return $this->context->runFor($tenant, fn (): array => DB::transaction(function (): array {
            $existing = JobKind::query()->pluck('name')->all();

            $kinds = 0;
            $fields = 0;
            $order = 0;

            foreach (JobKindDefaults::kinds() as $definition) {
                $order++;

                if (in_array($definition['name'], $existing, true)) {
                    continue;
                }

                $kind = JobKind::create([
                    'name' => $definition['name'],
                    'description' => $definition['description'],
                    // See JobKindDefaults: deliberately not protected. The
                    // refusal that matters is "a job is filed under it".
                    'is_system' => false,
                    'is_active' => true,
                    'display_order' => $order,
                ]);

                $kinds++;
                $position = 0;

                foreach ($definition['attributes'] as $field) {
                    $type = $this->shape->type($field['data_type'] ?? 'text');

                    $kind->fields()->create([
                        'key' => $this->shape->key($field['key'] ?? null, $field['label']),
                        'label' => $field['label'],
                        'data_type' => $type,
                        // Through the same normaliser the API writes go
                        // through, so a seeded field and a typed one cannot
                        // differ in shape — a unit the workshop does not have
                        // is dropped here exactly as it would be there.
                        'unit_code' => $this->shape->unitCode($type, $field['unit_code'] ?? null),
                        'is_required' => false,
                        'options' => $this->shape->options($type, $field['options'] ?? null),
                        'display_order' => $position++,
                        'is_active' => true,
                    ]);

                    $fields++;
                }
            }

            return ['kinds' => $kinds, 'fields' => $fields];
        }));
    }
}
