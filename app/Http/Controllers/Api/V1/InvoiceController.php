<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\InvoiceShare;
use App\Services\Accounting\InvoiceDocumentService;
use App\Services\Accounting\InvoiceShareService;
use App\Services\Accounting\TransactionService;
use App\Support\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * The customer's copy of a document, and the link that publishes it.
 *
 * Separate from {@see TransactionController} on purpose. That controller is
 * about what a transaction *is*; this one is about the piece of paper the
 * customer ends up holding, and the two carry different fields on purpose — see
 * {@see InvoiceDocumentService} for why the omission is structural rather than a
 * flag.
 */
class InvoiceController extends Controller
{
    public function __construct(
        private readonly TransactionService $transactions,
        private readonly InvoiceDocumentService $documents,
        private readonly InvoiceShareService $shares,
    ) {}

    /**
     * GET /api/v1/transactions/{transaction}/invoice
     *
     * The document, for printing.
     *
     * A payload rather than rendered HTML, because there is one renderer in this
     * application and it is on the client — `components/invoice-document.js`
     * paints both this and the public page. An endpoint returning markup would
     * be a second place the invoice's layout lives, and the two would drift the
     * first time somebody adjusted a column.
     *
     * READ:TRANSACTIONS. Reading a document the workshop already holds asks no
     * more of a grant than reading the transaction behind it does.
     */
    public function show(int $transaction): JsonResponse
    {
        $record = $this->transactions->find($transaction);

        $share = $this->shares->liveFor($record);

        return ApiResponse::success($this->documents->for($record), null, 200, [
            // Whether the customer can already open this, and where. In `meta`
            // rather than in the document, because it is a fact about the
            // workshop's decision to publish and not a line on the invoice — the
            // public page is built from the same document array and must not
            // carry it.
            'share' => $share === null ? null : $this->shareShape($share),
            'shareable' => $this->shares->isShareable($record),
        ]);
    }

    /**
     * POST /api/v1/transactions/{transaction}/share
     *
     * Publish the invoice, or hand back the link it already has.
     *
     * WRITE:TRANSACTIONS, which is the grant the person who wrote the bill
     * holds. That is deliberate and it is the honest reading: sending the
     * customer their invoice is the last step of raising it, and gating it any
     * higher would mean the clerk at the counter had to fetch the owner to
     * finish a sale. Gating it on READ would be worse in the other direction —
     * everyone who may look at the day book could publish any invoice on it.
     */
    public function share(int $transaction, Request $request): JsonResponse
    {
        $record = $this->transactions->find($transaction);

        $share = $this->shares->issue($record, $request->user()?->getAuthIdentifier());

        return ApiResponse::success($this->shareShape($share), 'Link ready to share.');
    }

    /**
     * DELETE /api/v1/transactions/{transaction}/share
     *
     * Stop the link working. Immediate, and it cannot be undone — a new share
     * mints a new token, so anybody holding the old URL keeps holding a URL that
     * answers 404 for ever.
     *
     * Not an error when nothing was shared. "Make sure this is not public" has
     * been satisfied either way, and a 404 there would send somebody looking for
     * a link that was never there.
     */
    public function revoke(int $transaction, Request $request): JsonResponse
    {
        $record = $this->transactions->find($transaction);

        $closed = $this->shares->revoke($record, $request->user()?->getAuthIdentifier());

        return ApiResponse::success(null, $closed === 0
            ? 'This invoice was not shared.'
            : 'The link has stopped working.');
    }

    /**
     * @return array<string, mixed>
     */
    private function shareShape(InvoiceShare $share): array
    {
        return [
            // Absolute, because the whole point is that it survives being pasted
            // into WhatsApp — a relative path stops being a link the moment it
            // leaves the browser.
            'url' => $share->url(),
            'shared_at' => $share->created_at?->toIso8601String(),
            'shared_by' => $share->creator?->name,
        ];
    }
}
