<?php

namespace App\Services\Workshop;

use App\Exceptions\ResourceNotFoundException;
use App\Exceptions\Workshop\JobKindException;
use App\Models\JobKind;
use App\Models\JobKindAttribute;
use App\Repositories\Contracts\JobKindRepositoryInterface;
use App\Support\Catalogue\AttributeFieldShape;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;

/**
 * The Kind master: what a workshop takes in, and what it asks about each.
 *
 * The bench's counterpart to `ItemCategoryService` and `ItemAttributeService`,
 * and deliberately one service where the catalogue has two: a kind is a name, a
 * description and a list of questions, with no parent to resolve, no HSN or SAC
 * to default, no GST rate and no unit. Splitting that in two would be two files
 * to open to answer one question.
 *
 * ## What it refuses, and why each one is a real loss
 *
 * A definition something already depends on may be **switched off, not
 * removed** — the rule the catalogue's masters are built on. Here that is:
 *
 *   * a kind with jobs filed under it, because `specs` is keyed by the fields
 *     under it and a deleted kind orphans every bag at once;
 *   * a field a job has answered, for the same reason one key at a time;
 *   * a dropdown choice jobs are filed under, which is the quiet one — nothing
 *     rewrites a bag, so the card goes on reading "Copper" while the list no
 *     longer offers it, and the next person to open that job loses the value by
 *     looking at it.
 *
 * ## And one thing it deliberately does not refuse
 *
 * Making a field required. `ItemAttributeService` refuses that while products
 * exist without a value, because a product with no rating is not identifiable
 * afterwards. A bench cannot say that: the pump whose plate nobody could read
 * is already standing there. `is_required` is stored and never enforced on a
 * job — see {@see JobKindAttribute}.
 */
class JobKindService
{
    public function __construct(
        private readonly JobKindRepositoryInterface $kinds,
        private readonly AttributeFieldShape $shape,
    ) {}

    private ?bool $installed = null;

    /** @var Collection<int, JobKind>|null */
    private ?Collection $indexed = null;

    /**
     * Whether the schema step has been run on this server.
     *
     * §4.6: the tables are SQL an operator runs by hand, in a window they
     * chose, so this code is deployed before they exist and has to work in that
     * window. This is the single place that is decided — the bench then falls
     * back to the list it read before, which is the catalogue's categories, and
     * a workshop notices nothing until the window closes.
     *
     * Memoised per instance rather than per process, for the reason
     * {@see \App\Services\Inventory\ItemComponentService::isInstalled()} is: a
     * test that builds the table part-way through a run would otherwise be
     * answered from a cache taken before it existed.
     */
    public function isInstalled(): bool
    {
        return $this->installed ??= Schema::hasTable('job_kinds');
    }

    /* ---------------------------------------------------------------------
     | Reading
     |-------------------------------------------------------------------- */

    /**
     * @param  array<string, mixed>  $filters
     * @return Collection<int, JobKind>
     */
    public function all(array $filters = []): Collection
    {
        if (! $this->isInstalled()) {
            return new Collection;
        }

        return $this->kinds->all($filters);
    }

    /**
     * Every kind, keyed by id, resolved once per request.
     *
     * `attachSpecSchema()` asks for one kind per distinct id on a page of jobs,
     * and a lookup each would be a query per kind to print a label (§7.2). The
     * whole list is eight or ten rows.
     *
     * @return Collection<int, JobKind>
     */
    public function byId(): Collection
    {
        return $this->indexed ??= $this->all()->keyBy(fn (JobKind $kind) => (int) $kind->id);
    }

    public function find(int $id): JobKind
    {
        return $this->kinds->findById($id)
            ?? throw new ResourceNotFoundException('Job kind', $id);
    }

    /**
     * @return array<string, array<string, mixed>>
     */
    public function schemaFor(JobKind $kind): array
    {
        return $kind->attributeSchema();
    }

    /**
     * What stands in the way of deleting this kind.
     *
     * @return array<string, int>
     */
    public function usageFor(JobKind $kind): array
    {
        return ['jobs' => $this->kinds->jobCount((int) $kind->id)];
    }

    /**
     * @param  array<int, int>  $kindIds
     * @return array<int, int>
     */
    public function jobCounts(array $kindIds): array
    {
        return $this->kinds->jobCounts($kindIds);
    }

    /* ---------------------------------------------------------------------
     | Writing a kind
     |-------------------------------------------------------------------- */

    /**
     * @param  array<string, mixed>  $data
     */
    public function create(array $data): JobKind
    {
        $name = trim((string) $data['name']);

        $this->assertNameAvailable($name);

        $kind = $this->kinds->create([
            'name' => $name,
            'description' => $this->shape->trimmed($data['description'] ?? null),
            'is_system' => false,
            'is_active' => true,
            'display_order' => (int) ($data['display_order'] ?? 0),
        ]);

        Log::info('job_kinds.created', ['job_kind_id' => $kind->id]);

        return $kind;
    }

    /**
     * @param  array<string, mixed>  $data
     */
    public function update(int $id, array $data): JobKind
    {
        $kind = $this->find($id);
        $attributes = [];

        if (array_key_exists('name', $data)) {
            $name = trim((string) $data['name']);
            $this->assertNameAvailable($name, (int) $kind->id);
            $attributes['name'] = $name;
        }

        if (array_key_exists('description', $data)) {
            $attributes['description'] = $this->shape->trimmed($data['description']);
        }

        if (array_key_exists('display_order', $data)) {
            $attributes['display_order'] = (int) $data['display_order'];
        }

        // A seeded kind may be renamed, re-described and switched off. Only
        // deleting it is refused — jobs already booked in refer to what it
        // means.
        if (array_key_exists('is_active', $data)) {
            $attributes['is_active'] = (bool) $data['is_active'];
        }

        if ($attributes === []) {
            return $kind;
        }

        return $this->kinds->update($kind, $attributes);
    }

    public function delete(int $id): void
    {
        $kind = $this->find($id);

        if ($kind->isProtected()) {
            throw JobKindException::kindProtected((int) $kind->id, $kind->name);
        }

        $jobs = $this->kinds->jobCount((int) $kind->id);

        if ($jobs > 0) {
            throw JobKindException::kindHasJobs((int) $kind->id, $kind->name, $jobs);
        }

        // The fields go with it — `job_kind_attributes` cascades — and that is
        // safe only because no job refers to the kind, which is what the check
        // above establishes.
        $this->kinds->delete($kind);

        Log::info('job_kinds.deleted', ['job_kind_id' => $id]);
    }

    /* ---------------------------------------------------------------------
     | Writing a field
     |-------------------------------------------------------------------- */

    /**
     * @param  array<string, mixed>  $data
     */
    public function createField(JobKind $kind, array $data): JobKindAttribute
    {
        $label = trim((string) $data['label']);
        $key = $this->shape->key($data['key'] ?? null, $label);
        $type = $this->shape->type($data['data_type'] ?? null);

        if ($this->kinds->fieldKeyExists($kind, $key)) {
            throw JobKindException::fieldKeyTaken($key);
        }

        return $this->kinds->createField($kind, [
            'key' => $key,
            'label' => $label,
            'data_type' => $type,
            'unit_code' => $this->shape->unitCode($type, $data['unit_code'] ?? null),
            'is_required' => (bool) ($data['is_required'] ?? false),
            'default_value' => $this->shape->trimmed($data['default_value'] ?? null),
            'options' => $this->shape->options($type, $data['options'] ?? null),
            'min_value' => $this->shape->bound($type, $data['min_value'] ?? null),
            'max_value' => $this->shape->bound($type, $data['max_value'] ?? null),
            'help_text' => $this->shape->trimmed($data['help_text'] ?? null),
            'display_order' => (int) ($data['display_order'] ?? 0),
            'is_active' => true,
        ]);
    }

    /**
     * @param  array<string, mixed>  $data
     */
    public function updateField(JobKind $kind, int $fieldId, array $data): JobKindAttribute
    {
        $field = $this->kinds->findField($kind, $fieldId)
            ?? throw new ResourceNotFoundException('Job kind field', $fieldId);

        // The key is write-once and is not read from $data at all: renaming it
        // would not rename it inside the bags already written, it would orphan
        // every one of them.
        $type = array_key_exists('data_type', $data)
            ? $this->shape->type($data['data_type'])
            : $field->data_type;

        $attributes = ['data_type' => $type];

        if (array_key_exists('label', $data)) {
            $attributes['label'] = trim((string) $data['label']);
        }

        if (array_key_exists('unit_code', $data) || $type !== $field->data_type) {
            $attributes['unit_code'] = $this->shape->unitCode(
                $type,
                $data['unit_code'] ?? $field->unit_code,
            );
        }

        if (array_key_exists('is_required', $data)) {
            $attributes['is_required'] = (bool) $data['is_required'];
        }

        if (array_key_exists('default_value', $data)) {
            $attributes['default_value'] = $this->shape->trimmed($data['default_value']);
        }

        if (array_key_exists('options', $data) || $type !== $field->data_type) {
            $options = $this->shape->options($type, $data['options'] ?? $field->options);

            if ($options !== null) {
                $this->assertOptionsStillCover($field, $options);
            }

            $attributes['options'] = $options;
        }

        foreach (['min_value', 'max_value'] as $bound) {
            if (array_key_exists($bound, $data) || $type !== $field->data_type) {
                $attributes[$bound] = $this->shape->bound($type, $data[$bound] ?? $field->{$bound});
            }
        }

        if (array_key_exists('help_text', $data)) {
            $attributes['help_text'] = $this->shape->trimmed($data['help_text']);
        }

        if (array_key_exists('display_order', $data)) {
            $attributes['display_order'] = (int) $data['display_order'];
        }

        if (array_key_exists('is_active', $data)) {
            $attributes['is_active'] = (bool) $data['is_active'];
        }

        return $this->kinds->updateField($field, $attributes);
    }

    public function deleteField(JobKind $kind, int $fieldId): void
    {
        $field = $this->kinds->findField($kind, $fieldId)
            ?? throw new ResourceNotFoundException('Job kind field', $fieldId);

        $answered = $this->kinds->answeredCount($field);

        if ($answered > 0) {
            throw JobKindException::fieldAnswered((int) $field->id, $field->label, $answered);
        }

        $this->kinds->deleteField($field);
    }

    /**
     * The order the questions are asked in.
     *
     * A specification reads in the order a person reciting it would say it — 5
     * HP, 3 phase, 1440 RPM — so this is the workshop's own and not
     * alphabetical.
     *
     * @param  array<int, int>  $orderedIds
     * @return Collection<int, JobKindAttribute>
     */
    public function reorderFields(JobKind $kind, array $orderedIds): Collection
    {
        DB::transaction(function () use ($kind, $orderedIds) {
            foreach (array_values($orderedIds) as $position => $id) {
                $field = $this->kinds->findField($kind, (int) $id);

                if ($field === null) {
                    continue;
                }

                $this->kinds->updateField($field, ['display_order' => $position]);
            }
        });

        return $this->find((int) $kind->id)->resolvedAttributes(false);
    }

    /* ---------------------------------------------------------------------
     | Refusals
     |-------------------------------------------------------------------- */

    private function assertNameAvailable(string $name, ?int $exceptId = null): void
    {
        if ($this->kinds->nameExists($name, $exceptId)) {
            throw JobKindException::kindNameTaken($name);
        }
    }

    /**
     * A choice may not be dropped while a job is filed under it.
     *
     * @param  array<int, string>  $options
     */
    private function assertOptionsStillCover(JobKindAttribute $field, array $options): void
    {
        if (! $field->data_type->hasOptions()) {
            return;
        }

        $missing = array_values(array_diff($this->kinds->answersFor($field), $options));

        if ($missing !== []) {
            throw JobKindException::fieldOptionsStillUsed((int) $field->id, $field->label, $missing);
        }
    }
}
