<?php

namespace Database\Factories;

use App\Enums\UnitOfMeasure;
use App\Models\WorkshopJob;
use App\Models\WorkshopJobPart;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<WorkshopJobPart>
 */
class WorkshopJobPartFactory extends Factory
{
    protected $model = WorkshopJobPart::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            // tenant_id is stamped by BelongsToTenant from the current context;
            // item_id and variant_id are left to the caller, because a part that
            // conjured its own catalogue entry would hide exactly the pairing
            // these tests are about.
            'workshop_job_id' => WorkshopJob::factory(),
            'description' => fake()->randomElement([
                'Ball Bearing 6205 ZZ',
                'Copper Winding Wire 22 SWG',
                'Rewinding labour',
            ]),
            'quantity' => '1.000',
            'unit' => 'piece',
            'unit_price' => '250.00',
            'discount_amount' => '0.00',
            'memo' => null,
            // Unbilled. A part that arrived already pointing at an invoice line
            // would be a part nothing ever wrote.
            'transaction_line_id' => null,
        ];
    }
}
