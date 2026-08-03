<?php

namespace App\Services\Accounting;

use App\Enums\AccountType;
use App\Enums\SystemAccount;
use App\Exceptions\Accounting\SystemAccountImmutableException;
use App\Exceptions\ConflictException;
use App\Exceptions\ResourceNotFoundException;
use App\Models\ChartOfAccount;
use App\Repositories\Contracts\ChartOfAccountRepositoryInterface;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Log;
use RuntimeException;

/**
 * Reading and maintaining a workshop's chart of accounts.
 *
 * The chart is structure, not bookkeeping: nothing here posts anything. The
 * posting engine consumes it through {@see system()}, which is the single
 * approved way for business logic to reach a named account.
 */
class ChartOfAccountService
{
    public function __construct(
        private readonly ChartOfAccountRepositoryInterface $accounts,
    ) {}

    /* ---------------------------------------------------------------------
     | Reading
     |-------------------------------------------------------------------- */

    /**
     * @param  array{search?: string|null, type?: string|null, is_active?: bool|null, is_system?: bool|null, sort?: string|null, direction?: string|null}  $filters
     * @return LengthAwarePaginator<int, ChartOfAccount>
     */
    public function paginate(array $filters, int $perPage = 50): LengthAwarePaginator
    {
        return $this->accounts->paginate($filters, $perPage);
    }

    /**
     * @return Collection<int, ChartOfAccount>
     */
    public function all(bool $activeOnly = false): Collection
    {
        return $this->accounts->all($activeOnly);
    }

    public function find(int $id): ChartOfAccount
    {
        return $this->accounts->findById($id)
            ?? throw new ResourceNotFoundException('Account', $id);
    }

    /**
     * Resolve one of the engine's known accounts for the current tenant.
     *
     * The posting engine's only entry point into the chart. It throws rather
     * than returning null because there is no sensible way to continue: a
     * transaction that cannot find its Sales account must not post half of
     * itself, and a missing system account is an install fault, not a
     * user error.
     */
    public function system(SystemAccount $account): ChartOfAccount
    {
        return $this->accounts->findBySystemKey($account)
            ?? throw new RuntimeException(
                "The [{$account->value}] account is missing from this workshop's chart of accounts. ".
                'Run `php artisan accounts:seed` to restore it.'
            );
    }

    /* ---------------------------------------------------------------------
     | Writing
     |-------------------------------------------------------------------- */

    /**
     * Add an account of the workshop's own — "Rent", "Electricity", a second
     * bank account.
     *
     * @param  array{code: string, name: string, type: string, description?: string|null}  $data
     */
    public function create(array $data): ChartOfAccount
    {
        $type = AccountType::from($data['type']);
        $code = trim($data['code']);
        $name = trim($data['name']);

        $this->assertCodeInBand($code, $type);
        $this->assertCodeAvailable($code);
        $this->assertNameAvailable($name);

        $account = $this->accounts->create([
            'code' => $code,
            'name' => $name,
            'description' => $data['description'] ?? null,
            'type' => $type,
            // Only the provisioner creates system accounts; anything arriving
            // through the API is the workshop's own.
            'system_key' => null,
            'is_active' => true,
        ]);

        Log::info('accounts.created', ['account_id' => $account->id, 'code' => $account->code]);

        return $account;
    }

    /**
     * @param  array{code?: string, name?: string, description?: string|null, is_active?: bool}  $data
     */
    public function update(int $id, array $data): ChartOfAccount
    {
        $account = $this->find($id);

        $attributes = [];

        if (array_key_exists('name', $data)) {
            $name = trim($data['name']);

            if ($name !== $account->name) {
                $this->assertNameAvailable($name, $account->id);
                $attributes['name'] = $name;
            }
        }

        if (array_key_exists('description', $data)) {
            $attributes['description'] = $data['description'];
        }

        if (array_key_exists('code', $data)) {
            $code = trim($data['code']);

            if ($code !== $account->code) {
                // Renumbering a system account would scramble every report
                // that groups by code, and any future Tally mapping with it.
                if ($account->isSystem()) {
                    throw SystemAccountImmutableException::field('code');
                }

                $this->assertCodeInBand($code, $account->type);
                $this->assertCodeAvailable($code, $account->id);
                $attributes['code'] = $code;
            }
        }

        if (array_key_exists('is_active', $data) && $data['is_active'] !== $account->is_active) {
            if ($account->isSystem()) {
                throw SystemAccountImmutableException::archiving();
            }

            $attributes['is_active'] = $data['is_active'];
        }

        // `type` is absent by design, for system and custom accounts alike:
        // reclassifying an account would silently move every journal entry
        // ever posted against it to a different financial statement.

        if ($attributes === []) {
            return $account;
        }

        $account = $this->accounts->update($account, $attributes);

        Log::info('accounts.updated', [
            'account_id' => $account->id,
            'fields' => array_keys($attributes),
        ]);

        return $account;
    }

    /* ---------------------------------------------------------------------
     | Invariants
     |-------------------------------------------------------------------- */

    private function assertCodeInBand(string $code, AccountType $type): void
    {
        if ($type->acceptsCode($code)) {
            return;
        }

        [$low, $high] = $type->codeRange();

        // "An asset", "An expense", "An equity" — but "A liability".
        $article = str_contains('aeiou', substr($type->value, 0, 1)) ? 'An' : 'A';

        throw new ConflictException(
            "{$article} {$type->value} account must be numbered between {$low} and {$high}.",
            'ACCOUNT_CODE_OUT_OF_BAND',
            ['field' => 'code', 'expected_range' => [$low, $high]],
        );
    }

    private function assertCodeAvailable(string $code, ?int $exceptId = null): void
    {
        if ($this->accounts->codeExists($code, $exceptId)) {
            throw new ConflictException(
                "An account numbered {$code} already exists.",
                'ACCOUNT_CODE_TAKEN',
                ['field' => 'code'],
            );
        }
    }

    private function assertNameAvailable(string $name, ?int $exceptId = null): void
    {
        if ($this->accounts->nameExists($name, $exceptId)) {
            throw new ConflictException(
                "An account named \"{$name}\" already exists.",
                'ACCOUNT_NAME_TAKEN',
                ['field' => 'name'],
            );
        }
    }
}
