<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * A link that lets a customer read one invoice without an account.
 *
 * The workshop's actual delivery mechanism is WhatsApp. What a counter wants is
 * to hand over a URL the customer can open on their phone, keep, and show back
 * at the shop — and to be able to take it away again when the relationship
 * sours or the number turns out to have been wrong.
 *
 * ## Why a table and not a column on `transactions`
 *
 * Because `transactions` refuses to be written to. {@see \App\Models\Transaction}
 * allows exactly `status` to change once a document is posted, plus two
 * write-once provenance columns — and a share link is neither. It is issued
 * *after* posting, revoked later, and issued again after that, which is the one
 * shape a write-once column cannot hold. Widening the guard to admit a
 * non-financial column would leave the next person a precedent for widening it
 * again, and that guard is the reason a posted figure can be trusted.
 *
 * It is also the honest model. Publishing a document is an act with its own
 * lifetime, its own author and its own end; it is not a property of the
 * document any more than posting it was.
 *
 * ## Why this is its own audit trail
 *
 * {@see \App\Enums\AuditResource} deliberately covers no transaction, on the
 * grounds that nothing about a posted one can change and `created_by` and
 * `posted_at` already record who and when. The same argument holds here and is
 * why there is no `audit_logs` row for a share: `created_by`/`created_at` and
 * `revoked_by`/`revoked_at` are the trail, on the row the act actually produced.
 * A revoked share is kept rather than deleted for exactly that reason — "this
 * invoice was public between Tuesday and Friday, and Kavita ended it" is a
 * question a workshop can be asked, and a deleted row answers it with silence.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('invoice_shares', function (Blueprint $table) {
            $table->id();

            // NOT NULL — tenant-owned, enforced by TenantIsolationInvariantTest.
            $table->foreignId('tenant_id')->constrained('tenants')->restrictOnDelete();

            /*
            | The document being published.
            |
            | restrictOnDelete, like every other reference to a transaction: a
            | posted one is never deleted, and the failure mode to foreclose is a
            | cascade quietly taking the trail with it.
            */
            $table->foreignId('transaction_id')->constrained('transactions')->restrictOnDelete();

            /*
            | The credential.
            |
            | Unique across every workshop, not merely within one, because it is
            | resolved *before* tenancy is known — the token is what says which
            | workshop's books to open. See PublicInvoiceController.
            |
            | Forty characters from Str::random, which is 62^40 of keyspace. That
            | is not "hard to guess"; it is the same order as a session id, and
            | the reason the link needs no second factor. Rate limiting on the
            | public route is belt to that braces.
            */
            $table->string('token', 64)->unique();

            /*
            | Who published it, and who ended it. Nullable because a user may be
            | removed from the workshop long after — nullOnDelete rather than
            | restrict, since losing "who shared it" is a smaller loss than
            | refusing to let a workshop delete a member who once shared a bill.
            */
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();

            $table->timestamp('revoked_at')->nullable();
            $table->foreignId('revoked_by')->nullable()->constrained('users')->nullOnDelete();

            $table->timestamps();

            /*
            | "Is this invoice shared, and by which link" — the read behind the
            | drawer's share panel, and the only query this table serves besides
            | the token lookup.
            |
            | Deliberately not unique. MySQL counts NULLs as distinct, so a
            | unique index over (transaction_id, revoked_at) would not constrain
            | the live rows at all — it would only forbid revoking two shares in
            | the same second. One live share per document is instead kept by
            | InvoiceShareService, which reuses the live one rather than issuing a
            | second, and revokes *every* live one when asked — so even a race
            | that produced two links cannot leave one of them behind.
            */
            $table->index(['tenant_id', 'transaction_id', 'revoked_at'], 'invoice_shares_document');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('invoice_shares');
    }
};
