<?php

namespace Database\Factories;

use App\Enums\WorkshopJobStatus;
use App\Models\Party;
use App\Models\WorkshopJob;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<WorkshopJob>
 */
class WorkshopJobFactory extends Factory
{
    protected $model = WorkshopJob::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            // tenant_id is stamped by BelongsToTenant from the current context.
            //
            // The number is a factory number, not a sequence one: taking it from
            // DocumentNumberService would need a database transaction around
            // every factory call, and a test that wants a real number posts
            // through JobService like the application does.
            'job_no' => 'JOB/26-27/'.fake()->unique()->numberBetween(1, 99999),
            'party_id' => Party::factory()->customer(),
            'item_id' => null,
            'hp' => fake()->randomElement(['1', '2', '5', '7.5', '10']),
            'brand' => fake()->randomElement(['Crompton', 'Kirloskar', 'Havells', 'ABB']),
            'model' => fake()->bothify('??-####'),
            'serial_no' => fake()->bothify('SN########'),
            'phase' => fake()->randomElement(['1-phase', '3-phase']),
            'complaint' => fake()->randomElement([
                'Winding burnt, not starting',
                'Humming but not turning',
                'Overheating after ten minutes',
                'Bearing noise at load',
            ]),
            'received_date' => now()->toDateString(),
            'promised_date' => null,
            'status' => WorkshopJobStatus::Received,
            'estimate_lines' => null,
            'estimate_approved_at' => null,
            'delivered_at' => null,
            'notes' => null,
        ];
    }

    public function withStatus(WorkshopJobStatus $status): static
    {
        return $this->state(fn (array $attributes) => ['status' => $status->value]);
    }

    /**
     * The state most tests want: work has started, so the job can be billed.
     */
    public function inProgress(): static
    {
        return $this->withStatus(WorkshopJobStatus::InProgress);
    }

    public function cancelled(): static
    {
        return $this->withStatus(WorkshopJobStatus::Cancelled);
    }

    public function forParty(Party $party): static
    {
        return $this->state(fn (array $attributes) => ['party_id' => $party->id]);
    }

    /**
     * Promised back before today and still on the bench — what an overdue
     * worklist is measured against.
     */
    public function overdue(int $daysLate = 3): static
    {
        return $this->state(fn (array $attributes) => [
            'received_date' => now()->subDays($daysLate + 7)->toDateString(),
            'promised_date' => now()->subDays($daysLate)->toDateString(),
        ]);
    }
}
