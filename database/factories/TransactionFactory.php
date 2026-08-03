<?php

namespace Database\Factories;

use App\Enums\TransactionSource;
use App\Enums\TransactionStatus;
use App\Enums\TransactionType;
use App\Models\Transaction;
use App\Support\Money;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * Drafts only, deliberately.
 *
 * There is no `posted()` state and there must not be one: a posted transaction
 * with no journal entries — or with entries a factory invented — is exactly the
 * corruption this module exists to prevent, and a test that started from one
 * would be asserting against books that could not occur in production.
 *
 * To get a posted transaction in a test, post one through the engine. That is
 * also the only way to get one in the application.
 *
 * @extends Factory<Transaction>
 */
class TransactionFactory extends Factory
{
    protected $model = Transaction::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            // tenant_id is stamped by BelongsToTenant from the current context.
            'type' => TransactionType::Journal,
            'status' => TransactionStatus::Draft,
            'source' => TransactionSource::Manual,
            'date' => now()->toDateString(),
            'total' => '0.00',
            'notes' => fake()->sentence(),
            'draft_lines' => [],
            'created_by' => null,
            'posted_at' => null,
        ];
    }

    /**
     * A draft carrying the given lines, in the shape
     * `[[account_id, debit, credit, memo]]`.
     *
     * @param  array<int, array<string, mixed>>  $lines
     */
    public function withLines(array $lines): static
    {
        return $this->state(fn (array $attributes) => [
            'draft_lines' => array_map(fn (array $line) => [
                'account_id' => (int) $line['account_id'],
                'debit' => (string) ($line['debit'] ?? '0.00'),
                'credit' => (string) ($line['credit'] ?? '0.00'),
                'memo' => $line['memo'] ?? null,
            ], $lines),
            // Money, not array_sum on floats — the rule holds in test fixtures
            // as much as in the engine, and a fixture is where a bad example
            // gets copied from.
            'total' => Money::sum(array_map(
                fn (array $line) => Money::of($line['debit'] ?? 0),
                $lines,
            ))->amount(),
        ]);
    }
}
