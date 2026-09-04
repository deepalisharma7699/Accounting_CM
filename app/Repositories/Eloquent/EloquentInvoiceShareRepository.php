<?php

namespace App\Repositories\Eloquent;

use App\Models\InvoiceShare;
use App\Models\Transaction;
use App\Repositories\Contracts\InvoiceShareRepositoryInterface;
use App\Support\Tenancy\TenantContext;
use Illuminate\Support\Collection;

class EloquentInvoiceShareRepository implements InvoiceShareRepositoryInterface
{
    public function __construct(private readonly TenantContext $context) {}

    public function liveFor(Transaction $transaction): ?InvoiceShare
    {
        return InvoiceShare::query()
            ->where('transaction_id', $transaction->id)
            ->live()
            ->with('creator:id,name')
            ->latest('id')
            ->first();
    }

    public function allLiveFor(Transaction $transaction): Collection
    {
        return InvoiceShare::query()
            ->where('transaction_id', $transaction->id)
            ->live()
            ->get();
    }

    public function create(Transaction $transaction, string $token, ?int $userId): InvoiceShare
    {
        return InvoiceShare::create([
            'tenant_id' => $transaction->tenant_id,
            'transaction_id' => $transaction->id,
            'token' => $token,
            'created_by' => $userId,
        ]);
    }

    public function revoke(InvoiceShare $share, ?int $userId): InvoiceShare
    {
        $share->forceFill([
            'revoked_at' => now(),
            'revoked_by' => $userId,
        ])->save();

        return $share;
    }

    /**
     * Unscoped, and the only method here that is.
     *
     * `runWithoutScope` is reason 1 in {@see TenantContext::runWithoutScope()} —
     * "the authentication path, which resolves a user *in order to* establish
     * tenancy and therefore runs before it exists". A share token is a
     * credential in exactly that sense: nothing on the request says which
     * workshop is being asked for, and the token is the only thing that can.
     *
     * The query is by unique token and nothing else, so the widest result it can
     * return is one row. The caller establishes tenancy from it before reading
     * anything at all — see PublicInvoiceController.
     */
    public function findLiveByToken(string $token): ?InvoiceShare
    {
        return $this->context->runWithoutScope(
            fn () => InvoiceShare::query()
                ->where('token', $token)
                ->live()
                ->first()
        );
    }
}
