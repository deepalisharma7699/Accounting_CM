<?php

namespace App\Repositories\Contracts;

use App\Models\InvoiceShare;
use App\Models\Transaction;
use Illuminate\Support\Collection;

interface InvoiceShareRepositoryInterface
{
    /**
     * The link that currently opens this document, if there is one.
     *
     * Newest first, so a workshop that somehow holds two live links is shown the
     * one it most recently issued rather than an arbitrary row.
     */
    public function liveFor(Transaction $transaction): ?InvoiceShare;

    /**
     * Every live link for the document. Ordinarily nought or one; see
     * {@see \App\Services\Accounting\InvoiceShareService::revoke()} for why
     * revocation must not assume that.
     *
     * @return Collection<int, InvoiceShare>
     */
    public function allLiveFor(Transaction $transaction): Collection;

    public function create(Transaction $transaction, string $token, ?int $userId): InvoiceShare;

    public function revoke(InvoiceShare $share, ?int $userId): InvoiceShare;

    /**
     * Resolve a token to its share — **across every workshop**, because the
     * token is what says which workshop this is.
     *
     * The one deliberate hole in tenant isolation on this path, and the same
     * kind of hole the authentication path opens for the same reason: a
     * credential has to be resolved before the identity it carries can be
     * established. Revoked tokens resolve to null; they are over.
     *
     * Everything read *afterwards* is scoped to the tenant this returns — see
     * {@see \App\Http\Controllers\PublicInvoiceController}.
     */
    public function findLiveByToken(string $token): ?InvoiceShare;
}
