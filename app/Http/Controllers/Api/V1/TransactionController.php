<?php

namespace App\Http\Controllers\Api\V1;

use App\Enums\PaymentMode;
use App\Enums\TransactionSource;
use App\Enums\TransactionStatus;
use App\Enums\TransactionType;
use App\Http\Controllers\Controller;
use App\Http\Requests\Transaction\IndexTransactionRequest;
use App\Http\Requests\Transaction\ReverseTransactionRequest;
use App\Http\Requests\Transaction\StoreBillRequest;
use App\Http\Requests\Transaction\StoreExpenseRequest;
use App\Http\Requests\Transaction\StoreJournalRequest;
use App\Http\Requests\Transaction\StoreSettlementRequest;
use App\Http\Requests\Transaction\StoreStockAdjustmentRequest;
use App\Http\Requests\Transaction\UpdateTransactionRequest;
use App\Http\Resources\TransactionResource;
use App\Services\Accounting\BillService;
use App\Services\Accounting\Posting\PostingTemplateRegistry;
use App\Services\Accounting\TransactionService;
use App\Support\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Transactions and the ledger entries behind them.
 *
 * Two things are worth noticing about the shape of this controller. There is no
 * general `update` for a posted transaction — only a draft can be rewritten,
 * and a mistake in the books is corrected by `reverse`. And `destroy` only ever
 * reaches a draft, because a transaction that has entries cannot be deleted at
 * all: the model refuses it.
 */
class TransactionController extends Controller
{
    public function __construct(
        private readonly TransactionService $transactions,
        private readonly PostingTemplateRegistry $templates,
        private readonly BillService $bills,
    ) {}

    /**
     * GET /api/v1/transactions
     */
    public function index(IndexTransactionRequest $request): JsonResponse
    {
        return ApiResponse::paginated(
            $this->transactions->paginate($request->filters(), $request->perPage()),
            TransactionResource::class
        );
    }

    /**
     * GET /api/v1/transactions/counts
     *
     * How many transactions there are of each type and each status, so a tabbed
     * screen labels its tabs without a request per tab.
     *
     * The raw breakdown rather than one figure per tab, because which types a
     * tab covers is the screen's decision and not the API's: "Sales" on the
     * transactions screen means an invoice *and* the receipt that settles it,
     * and a server that hard-coded that grouping would have to be redeployed
     * when the screen changed its mind.
     *
     * Unfiltered, deliberately. These count the workshop's books rather than the
     * current search — a badge that shrank as somebody typed would be answering
     * a different question from the one it looks like it is answering.
     */
    public function counts(): JsonResponse
    {
        return ApiResponse::success($this->transactions->counts());
    }

    /**
     * GET /api/v1/transactions/meta
     *
     * The vocabulary of the transaction list — types that can actually be
     * posted, statuses, sources — so a client builds its filters and its forms
     * from the server's answer rather than from a hard-coded copy that drifts
     * as M6 to M11 add types.
     */
    public function meta(): JsonResponse
    {
        return ApiResponse::success([
            'types' => array_map(fn (TransactionType $type) => [
                'value' => $type->value,
                'label' => $type->label(),
                'accepts_raw_lines' => $type->acceptsRawLines(),
                // Which vocabulary a form has to speak, so a client renders the
                // right editor without a hard-coded list of type names that
                // drifts as M8 to M11 land.
                'has_document_lines' => $type->hasDocumentLines(),
                'accepts_payment_split' => $type->acceptsPaymentSplit(),
                'requires_payment_split' => $type->isSettlement(),
                'moves_stock' => $type->movesStock(),
                'requires_party' => $type->requiresParty(),
                'required_party_role' => $type->requiredPartyRole()?->value,
            ], $this->templates->postableTypes()),

            'statuses' => array_map(fn (TransactionStatus $status) => [
                'value' => $status->value,
                'label' => $status->label(),
                'is_editable' => $status->isEditable(),
            ], TransactionStatus::cases()),

            'sources' => array_map(fn (TransactionSource $source) => [
                'value' => $source->value,
                'label' => $source->label(),
            ], TransactionSource::cases()),

            // The ways money can move, with the label a form should put on the
            // reference field and whether it is required. Published rather than
            // hard-coded client-side so a payment form asks for "Cheque number"
            // without a second copy of the mapping to keep in step.
            'payment_modes' => array_map(fn (PaymentMode $mode) => [
                'value' => $mode->value,
                'label' => $mode->label(),
                'reference_label' => $mode->referenceLabel(),
                'requires_reference' => $mode->requiresReference(),
            ], PaymentMode::cases()),
        ]);
    }

    /**
     * GET /api/v1/transactions/{transaction}
     */
    public function show(int $transaction): JsonResponse
    {
        $record = $this->transactions->find($transaction);

        // Everything derived on read, and only for a document that has any: the
        // invoice footer, the margin, and the things somebody ought to look at.
        // Recomputed here rather than stored, so "why is this month's margin
        // down" is answerable long after the bill was posted.
        $meta = [];

        if ($record->type->hasDocumentLines() && ! $record->isDraft()) {
            $record->setRelation('lines', $this->bills->linesFor($record));

            $meta = array_filter([
                'tax' => $this->bills->taxSummaryFor($record),
                'margin' => $this->bills->marginFor($record),
                'warnings' => $this->bills->warningsFor($record),
            ], fn ($value) => $value !== null && $value !== []);
        }

        return ApiResponse::success(new TransactionResource($record), null, 200, $meta);
    }

    /**
     * POST /api/v1/transactions/journal
     *
     * A manual journal entry, posted outright or parked as a draft depending on
     * the payload's `post` flag.
     */
    public function storeJournal(StoreJournalRequest $request): JsonResponse
    {
        $transaction = $this->transactions->create(
            TransactionType::Journal,
            $request->payload(),
            $request->user(),
        );

        return ApiResponse::created(
            new TransactionResource($transaction),
            $transaction->isDraft() ? 'Draft saved.' : 'Transaction posted.'
        );
    }

    /**
     * POST /api/v1/transactions/payment
     *
     * Money paid out to a supplier — template D. Its own route rather than a
     * `direction` field on a shared one, so the URL says what happened and the
     * permission log reads the same way.
     */
    public function storePayment(StoreSettlementRequest $request): JsonResponse
    {
        return $this->createSettlement(TransactionType::Payment, $request);
    }

    /**
     * POST /api/v1/transactions/receipt
     *
     * Money collected from a customer — template E.
     */
    public function storeReceipt(StoreSettlementRequest $request): JsonResponse
    {
        return $this->createSettlement(TransactionType::Receipt, $request);
    }

    private function createSettlement(TransactionType $type, StoreSettlementRequest $request): JsonResponse
    {
        $transaction = $this->transactions->create($type, $request->payload(), $request->user());

        return ApiResponse::created(
            new TransactionResource($transaction),
            $transaction->isDraft()
                ? 'Draft saved.'
                : sprintf('%s recorded.', $type->label()),
        );
    }

    /**
     * POST /api/v1/transactions/sale
     *
     * Templates A and B — goods sold, labour billed, or both on one document.
     * Its own route rather than a `direction` field on a shared one, so the URL
     * says what happened and the permission log reads the same way.
     */
    public function storeSale(StoreBillRequest $request): JsonResponse
    {
        return $this->createBill(TransactionType::Sale, $request);
    }

    /**
     * POST /api/v1/transactions/purchase
     *
     * Template C — goods bought in, and the arrival that recomputes the weighted
     * average cost.
     */
    public function storePurchase(StoreBillRequest $request): JsonResponse
    {
        return $this->createBill(TransactionType::Purchase, $request);
    }

    private function createBill(TransactionType $type, StoreBillRequest $request): JsonResponse
    {
        $transaction = $this->transactions->create($type, $request->payload(), $request->user());

        // Warnings, not errors, and returned on the same response that confirms
        // the posting. Selling below cost is a real decision and so is billing
        // something the shelf says is not there — both post, and both have to be
        // put in front of somebody while they are still looking at the bill.
        $warnings = $transaction->isDraft() ? [] : $this->bills->warningsFor($transaction);

        return ApiResponse::created(
            new TransactionResource($transaction),
            $transaction->isDraft()
                ? 'Draft saved.'
                : sprintf('%s recorded.', $type->label()),
            $warnings === [] ? [] : ['warnings' => $warnings],
        );
    }

    /**
     * POST /api/v1/transactions/expense
     *
     * A running cost — template F. Separate from a purchase on purpose: a
     * purchase is something bought to sell or to fit, and an expense is what it
     * costs to be open. Keeping them apart is what lets a P&L separate gross
     * margin from overheads.
     */
    public function storeExpense(StoreExpenseRequest $request): JsonResponse
    {
        $transaction = $this->transactions->create(
            TransactionType::Expense,
            $request->payload(),
            $request->user(),
        );

        return ApiResponse::created(
            new TransactionResource($transaction),
            $transaction->isDraft() ? 'Draft saved.' : 'Expense recorded.'
        );
    }

    /**
     * POST /api/v1/transactions/stock-adjustment
     *
     * A stock-take correction — template G. Its own route rather than a flag on
     * a journal, because what it does is not "post these two accounts": it moves
     * a quantity, and the accounting is the consequence rather than the request.
     */
    public function storeStockAdjustment(StoreStockAdjustmentRequest $request): JsonResponse
    {
        $transaction = $this->transactions->create(
            TransactionType::StockAdjustment,
            $request->payload(),
            $request->user(),
        );

        return ApiResponse::created(
            new TransactionResource($transaction),
            $transaction->isDraft() ? 'Draft saved.' : 'Stock adjusted.'
        );
    }

    /**
     * PATCH /api/v1/transactions/{transaction}
     *
     * Drafts only, of any type — `lines` for a journal, `payments` for a
     * settlement. Editing something already in the books is refused by the
     * service and again by the model.
     */
    public function update(UpdateTransactionRequest $request, int $transaction): JsonResponse
    {
        return ApiResponse::success(
            new TransactionResource($this->transactions->update($transaction, $request->payload())),
            'Draft updated.'
        );
    }

    /**
     * POST /api/v1/transactions/{transaction}/post
     *
     * Authorise a draft. Its own route rather than a flag on the edit, so that
     * saving a change can never commit it to the ledger by accident.
     */
    public function post(Request $request, int $transaction): JsonResponse
    {
        return ApiResponse::success(
            new TransactionResource($this->transactions->post($transaction, $request->user())),
            'Transaction posted.'
        );
    }

    /**
     * POST /api/v1/transactions/{transaction}/reverse
     *
     * Cancel a posted transaction with a mirrored one. Both remain visible;
     * nothing is erased.
     */
    public function reverse(ReverseTransactionRequest $request, int $transaction): JsonResponse
    {
        $reversal = $this->transactions->reverse(
            $transaction,
            $request->input('date'),
            $request->input('reason'),
            $request->user(),
        );

        return ApiResponse::created(
            new TransactionResource($reversal),
            'Reversing entry posted.'
        );
    }

    /**
     * DELETE /api/v1/transactions/{transaction}
     *
     * Discards a draft. Anything that has reached the ledger is refused.
     */
    public function destroy(int $transaction): JsonResponse
    {
        $this->transactions->discard($transaction);

        return ApiResponse::message('Draft discarded.');
    }
}
