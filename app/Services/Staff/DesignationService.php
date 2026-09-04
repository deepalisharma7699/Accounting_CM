<?php

namespace App\Services\Staff;

use App\Exceptions\ConflictException;
use App\Exceptions\ResourceNotFoundException;
use App\Exceptions\Staff\DesignationInUseException;
use App\Models\StaffDesignation;
use App\Repositories\Contracts\StaffDesignationRepositoryInterface;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Log;

/**
 * The Designation Master — M22.
 *
 * A small service, and it exists for exactly one reason worth having: the
 * uniqueness rule and the delete guard have to hold wherever a designation is
 * written from, and a form request only guards the form. The staff module's
 * "add a designation" control on the employee form goes through here, and so
 * would an importer.
 */
class DesignationService
{
    public function __construct(
        private readonly StaffDesignationRepositoryInterface $designations,
    ) {}

    /* ---------------------------------------------------------------------
     | Reading
     |-------------------------------------------------------------------- */

    /**
     * @return Collection<int, StaffDesignation>
     */
    public function all(bool $activeOnly = false): Collection
    {
        return $this->designations->all($activeOnly);
    }

    /**
     * The trades a sale asks about by name — M22.
     *
     * @return Collection<int, StaffDesignation>
     */
    public function trackedOnSales(): Collection
    {
        return $this->designations->trackedOnSales();
    }

    /**
     * The list with a headcount against each, for the master screen.
     *
     * The counts are one query for the whole list rather than one per row, and
     * they are what makes "archive this" a decision somebody can take: a
     * designation nobody holds can go, one four people hold cannot.
     *
     * @return Collection<int, StaffDesignation>
     */
    public function withCounts(bool $activeOnly = false): Collection
    {
        $designations = $this->all($activeOnly);
        $counts = $this->designations->employeeCounts($designations->pluck('id')->all());

        foreach ($designations as $designation) {
            $designation->setAttribute('employee_count', $counts[(int) $designation->id] ?? 0);
        }

        return $designations;
    }

    public function find(int $id): StaffDesignation
    {
        return $this->designations->findById($id)
            ?? throw new ResourceNotFoundException('Designation', $id);
    }

    /* ---------------------------------------------------------------------
     | Writing
     |-------------------------------------------------------------------- */

    /**
     * @param  array{name: string, track_on_sales?: bool}  $data
     */
    public function create(array $data): StaffDesignation
    {
        $name = $this->normalise($data['name']);

        $this->assertNameAvailable($name);

        $designation = $this->designations->create([
            'name' => $name,
            'is_active' => true,
            // Off unless the caller said otherwise. A new trade nobody has
            // decided about does not appear on the counter's sale form.
            'track_on_sales' => (bool) ($data['track_on_sales'] ?? false),
        ]);

        Log::info('staff.designation.created', ['designation_id' => $designation->id]);

        return $designation;
    }

    /**
     * @param  array<string, mixed>  $data
     */
    public function update(int $id, array $data): StaffDesignation
    {
        $designation = $this->find($id);
        $attributes = [];

        if (array_key_exists('name', $data)) {
            $name = $this->normalise((string) $data['name']);

            if ($name !== $designation->name) {
                $this->assertNameAvailable($name, $designation->id);
                $attributes['name'] = $name;
            }
        }

        if (array_key_exists('is_active', $data)) {
            $attributes['is_active'] = (bool) $data['is_active'];
        }

        if (array_key_exists('track_on_sales', $data)) {
            $attributes['track_on_sales'] = (bool) $data['track_on_sales'];
        }

        if ($attributes === []) {
            return $designation;
        }

        $designation = $this->designations->update($designation, $attributes);

        Log::info('staff.designation.updated', [
            'designation_id' => $designation->id,
            'fields' => array_keys($attributes),
        ]);

        return $designation;
    }

    /**
     * Remove a designation nobody holds — a typo, or one added and never used.
     *
     * Anything in use is refused. That is not a convenience check:
     * `employees.designation_id` is nullOnDelete, so the database would happily
     * allow it and quietly blank the trade off every employee who held it. This
     * exists so the answer is an explanation with a way forward instead.
     */
    public function delete(int $id): void
    {
        $designation = $this->find($id);
        $count = $this->designations->employeeCount($designation->id);

        if ($count > 0) {
            throw DesignationInUseException::hasEmployees($designation->id, $designation->name, $count);
        }

        /*
        | The second way it can be in use — M22: invoices credited to this trade.
        |
        | Checked here rather than left to the database, which would refuse it
        | too — `transaction_staff.designation_id` is restrictOnDelete — but
        | would do it with an integrity error the caller cannot act on. The
        | employee check above is left first because it is the commoner case and
        | the cheaper query.
        */
        $attributed = $this->designations->attributionCount($designation->id);

        if ($attributed > 0) {
            throw DesignationInUseException::hasAttributions(
                $designation->id, $designation->name, $attributed
            );
        }

        $this->designations->delete($designation);

        Log::info('staff.designation.deleted', ['designation_id' => $designation->id]);
    }

    /* ---------------------------------------------------------------------
     | Invariants
     |-------------------------------------------------------------------- */

    /**
     * Whitespace folded to single spaces, exactly as a party name is: a pasted
     * word is commonly untidy rather than hostile, and refusing it for a line
     * break nobody can see would be a refusal nobody could act on.
     */
    private function normalise(string $name): string
    {
        return trim((string) preg_replace('/\s+/u', ' ', $name));
    }

    private function assertNameAvailable(string $name, ?int $exceptId = null): void
    {
        if (! $this->designations->nameExists($name, $exceptId)) {
            return;
        }

        throw new ConflictException(
            "There is already a designation called \"{$name}\". Two spellings of one trade is exactly what ".
            'this list exists to prevent — use the one that is already there.',
            'DESIGNATION_NAME_TAKEN',
            ['field' => 'name'],
        );
    }
}
