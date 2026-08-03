<?php

namespace App\Repositories\Eloquent;

use App\Enums\SystemAccount;
use App\Models\ChartOfAccount;
use App\Repositories\Contracts\ChartOfAccountRepositoryInterface;
use App\Support\Tenancy\TenantContext;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Support\Collection;

class EloquentChartOfAccountRepository implements ChartOfAccountRepositoryInterface
{
    /**
     * Columns a client is allowed to sort by. Anything else falls back to the
     * default, which keeps user input out of the ORDER BY clause.
     *
     * @var array<int, string>
     */
    private const SORTABLE = ['code', 'name', 'type', 'created_at'];

    /**
     * Per-request memo of resolved system accounts, keyed by tenant then by
     * system key.
     *
     * Once posting exists, a single sale resolves five or six of these. They
     * cannot change during a request, so re-reading them is pure waste.
     * Keyed by tenant because a request may legitimately cross tenants — a
     * platform admin, or a queued job iterating workshops.
     *
     * @var array<int, array<string, ChartOfAccount>>
     */
    private array $systemAccounts = [];

    public function __construct(private readonly TenantContext $context) {}

    public function findById(int $id): ?ChartOfAccount
    {
        return ChartOfAccount::find($id);
    }

    public function findByCode(string $code): ?ChartOfAccount
    {
        return ChartOfAccount::where('code', $code)->first();
    }

    public function findBySystemKey(SystemAccount $account): ?ChartOfAccount
    {
        $tenantId = $this->context->current() ?? 0;

        if (isset($this->systemAccounts[$tenantId][$account->value])) {
            return $this->systemAccounts[$tenantId][$account->value];
        }

        $resolved = ChartOfAccount::where('system_key', $account->value)->first();

        if ($resolved !== null) {
            $this->systemAccounts[$tenantId][$account->value] = $resolved;
        }

        return $resolved;
    }

    public function codeExists(string $code, ?int $exceptId = null): bool
    {
        return ChartOfAccount::where('code', $code)
            ->when($exceptId !== null, fn ($query) => $query->whereKeyNot($exceptId))
            ->exists();
    }

    public function nameExists(string $name, ?int $exceptId = null): bool
    {
        return ChartOfAccount::where('name', $name)
            ->when($exceptId !== null, fn ($query) => $query->whereKeyNot($exceptId))
            ->exists();
    }

    public function all(bool $activeOnly = false): Collection
    {
        return ChartOfAccount::query()
            ->when($activeOnly, fn ($query) => $query->active())
            ->orderBy('code')
            ->get();
    }

    public function create(array $attributes): ChartOfAccount
    {
        $account = ChartOfAccount::create($attributes);

        $this->forgetSystemAccounts();

        return $account;
    }

    public function update(ChartOfAccount $account, array $attributes): ChartOfAccount
    {
        $account->fill($attributes)->save();

        $this->forgetSystemAccounts();

        return $account->fresh();
    }

    public function paginate(array $filters, int $perPage): LengthAwarePaginator
    {
        $sort = in_array($filters['sort'] ?? null, self::SORTABLE, true)
            ? $filters['sort']
            : 'code';

        $direction = strtolower((string) ($filters['direction'] ?? 'asc')) === 'desc' ? 'desc' : 'asc';

        return ChartOfAccount::query()
            ->when(
                filled($filters['search'] ?? null),
                fn ($query) => $query->where(function ($query) use ($filters) {
                    $term = '%'.$filters['search'].'%';
                    $query->where('name', 'like', $term)->orWhere('code', 'like', $term);
                })
            )
            ->when(filled($filters['type'] ?? null), fn ($query) => $query->where('type', $filters['type']))
            ->when(
                ($filters['is_active'] ?? null) !== null,
                fn ($query) => $query->where('is_active', $filters['is_active'])
            )
            ->when(
                ($filters['is_system'] ?? null) !== null,
                fn ($query) => $filters['is_system']
                    ? $query->whereNotNull('system_key')
                    : $query->whereNull('system_key')
            )
            ->orderBy($sort, $direction)
            ->paginate($perPage)
            ->withQueryString();
    }

    /**
     * Dropped wholesale on any write. A rename cannot change which account a
     * system key points at, but an archived-then-restored row can, and a
     * stale model here would be handed to the posting engine.
     */
    private function forgetSystemAccounts(): void
    {
        $this->systemAccounts = [];
    }
}
