<?php

namespace App\Services\Inventory;

use App\Exceptions\Accounting\CatalogueMasterException;
use App\Exceptions\ResourceNotFoundException;
use App\Models\ItemBrand;
use App\Repositories\Contracts\ItemBrandRepositoryInterface;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Log;

/**
 * The Brand Master: whose the shop's products are.
 *
 * Deliberately the smallest master in the catalogue. A brand is an identity, not
 * a template — it decides nothing about how a product is taxed, counted or
 * described — so this service has exactly the rules a name needs and no more:
 *
 *   * **One name, one brand.** Two rows called "Crompton" would split a range in
 *     half and both halves would look right, which is the same reason categories,
 *     items and parties are unique by name.
 *   * **A brand products carry cannot be deleted.** It can be archived, which
 *     takes it off the create form and leaves it naming what is already filed
 *     under it. Deleting would make twelve unbranded things out of twelve
 *     Cromptons with nothing to say it happened.
 *
 * The rules live here rather than in a form request for the reason the
 * catalogue's rules always have: the importer and any future capture agent write
 * brands without passing through a controller.
 */
class ItemBrandService
{
    public function __construct(
        private readonly ItemBrandRepositoryInterface $brands,
    ) {}

    /* ---------------------------------------------------------------------
     | Reading
     |-------------------------------------------------------------------- */

    /**
     * @param  array{search?: string|null, is_active?: bool|null}  $filters
     * @return Collection<int, ItemBrand>
     */
    public function all(array $filters = []): Collection
    {
        return $this->brands->all($filters);
    }

    public function find(int $id): ItemBrand
    {
        return $this->brands->findById($id)
            ?? throw new ResourceNotFoundException('Brand', $id);
    }

    /**
     * The brands a picker should offer — active only, in the shop's order.
     *
     * An archived brand is deliberately absent: it is still the answer on every
     * product that already carries it, and it must not be offered as the answer
     * to a new one.
     *
     * @return Collection<int, ItemBrand>
     */
    public function selectable(): Collection
    {
        return $this->brands->all(['is_active' => true]);
    }

    /**
     * How many products carry a brand — the number the master prints against
     * each row, and the number every refusal here needs in it.
     *
     * @return array{items: int}
     */
    public function usageFor(ItemBrand $brand): array
    {
        return ['items' => $this->brands->itemCount((int) $brand->id)];
    }

    /* ---------------------------------------------------------------------
     | Writing
     |-------------------------------------------------------------------- */

    /**
     * @param  array{name: string, code?: string|null, description?: string|null, display_order?: int|null}  $data
     */
    public function create(array $data): ItemBrand
    {
        $name = trim((string) $data['name']);
        $code = $this->normaliseCode($data['code'] ?? null);

        $this->assertNameAvailable($name);

        if ($code !== null) {
            $this->assertCodeAvailable($code);
        }

        $brand = $this->brands->create([
            'name' => $name,
            'code' => $code,
            'description' => $this->trimmed($data['description'] ?? null),
            'is_active' => true,
            'display_order' => (int) ($data['display_order'] ?? 0),
        ]);

        Log::info('item_brands.created', ['brand_id' => $brand->id]);

        return $brand;
    }

    /**
     * @param  array<string, mixed>  $data
     */
    public function update(int $id, array $data): ItemBrand
    {
        $brand = $this->find($id);
        $attributes = [];

        if (array_key_exists('name', $data)) {
            $name = trim((string) $data['name']);

            if ($name !== $brand->name) {
                $this->assertNameAvailable($name, (int) $brand->id);
                $attributes['name'] = $name;
            }
        }

        if (array_key_exists('code', $data)) {
            $code = $this->normaliseCode($data['code']);

            if ($code !== $brand->code) {
                if ($code !== null) {
                    $this->assertCodeAvailable($code, (int) $brand->id);
                }

                $attributes['code'] = $code;
            }
        }

        if (array_key_exists('description', $data)) {
            $attributes['description'] = $this->trimmed($data['description']);
        }

        if (array_key_exists('display_order', $data)) {
            $attributes['display_order'] = (int) $data['display_order'];
        }

        // The archive control. Nothing is refused here: taking a brand off the
        // create form changes no product, which is exactly why it is the remedy
        // offered wherever a delete is refused.
        if (array_key_exists('is_active', $data)) {
            $attributes['is_active'] = (bool) $data['is_active'];
        }

        if ($attributes === []) {
            return $brand;
        }

        $brand = $this->brands->update($brand, $attributes);

        Log::info('item_brands.updated', ['brand_id' => $brand->id, 'fields' => array_keys($attributes)]);

        return $brand;
    }

    /**
     * Remove a brand no product carries — a typo, a duplicate caught early.
     */
    public function delete(int $id): void
    {
        $brand = $this->find($id);

        $items = $this->brands->itemCount((int) $brand->id);

        if ($items > 0) {
            throw CatalogueMasterException::brandInUse((int) $brand->id, $brand->name, $items);
        }

        $this->brands->delete($brand);

        Log::info('item_brands.deleted', ['brand_id' => $id]);
    }

    /* ---------------------------------------------------------------------
     | Resolution
     |-------------------------------------------------------------------- */

    /**
     * The brand a product is being filed under, or null where it has none.
     *
     * An unbranded bush is a real thing, so null is an answer rather than an
     * error. What is refused is an id that does not resolve — silently saving the
     * product with no brand would leave somebody looking at a create form that
     * said "Crompton" and a record that says nothing.
     *
     * An **archived** brand still resolves. It is off the picker, so this only
     * happens on an edit of a product that already carried it, and refusing there
     * would make the product unsaveable until somebody restored a brand they
     * deliberately retired.
     */
    public function resolve(int|string|null $id): ?ItemBrand
    {
        if ($id === null || $id === '') {
            return null;
        }

        return $this->find((int) $id);
    }

    /* ---------------------------------------------------------------------
     | Invariants
     |-------------------------------------------------------------------- */

    private function assertNameAvailable(string $name, ?int $exceptId = null): void
    {
        if ($this->brands->nameExists($name, $exceptId)) {
            throw CatalogueMasterException::brandNameTaken($name);
        }
    }

    private function assertCodeAvailable(string $code, ?int $exceptId = null): void
    {
        if ($this->brands->codeExists($code, $exceptId)) {
            throw CatalogueMasterException::brandCodeTaken($code);
        }
    }

    /**
     * Upper case, like an item's code and for the same reason: it is a short
     * handle people type, and "skf" and "SKF" being two codes would defeat the
     * point of having one.
     */
    private function normaliseCode(mixed $code): ?string
    {
        $code = $this->trimmed($code);

        return $code === null ? null : strtoupper($code);
    }

    private function trimmed(mixed $value): ?string
    {
        $value = trim((string) ($value ?? ''));

        return $value === '' ? null : $value;
    }
}
