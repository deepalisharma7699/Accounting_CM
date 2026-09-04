<?php

namespace App\Http\Controllers\Api\V1;

use App\Enums\PaymentMode;
use App\Enums\TransactionSource;
use App\Enums\TransactionStatus;
use App\Enums\TransactionType;
use App\Http\Controllers\Controller;
use App\Enums\PaymentStatus;
use App\Http\Requests\Transaction\AllocateSettlementRequest;
use App\Http\Requests\Transaction\IndexTransactionRequest;
use App\Http\Requests\Transaction\PreviewBillRequest;
use App\Http\Requests\Transaction\ReverseTransactionRequest;
use App\Http\Requests\Transaction\StoreBillRequest;
use App\Http\Requests\Transaction\StoreReturnRequest;
use App\Http\Requests\Transaction\StoreExpenseRequest;
use App\Http\Requests\Transaction\StoreJournalRequest;
use App\Http\Requests\Transaction\StoreSettlementRequest;
use App\Http\Requests\Transaction\StoreStockAdjustmentRequest;
use App\Http\Requests\Transaction\UpdateTransactionRequest;
use App\Http\Requests\Transaction\UpdateTransactionStaffRequest;
use App\Http\Resources\TransactionResource;
use App\Models\Transaction;
use App\Services\Accounting\BillPreviewService;
use App\Services\Accounting\BillService;
use App\Services\Accounting\Posting\PostingTemplateRegistry;
use App\Services\Accounting\ReturnService;
use App\Services\Accounting\SettlementService;
use App\Services\Accounting\TransactionService;
use App\Services\Staff\WorkAttributionService;
use App\Support\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

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
        private readonly BillPreviewService $preview,
        private readonly SettlementService $settlements,
        private readonly ReturnService $returns,
        // Who did the work an invoice was raised for — M22. Injected here rather
        // than reached for through the staff module's own controller, because
        // the counter clerk who writes the invoice holds TRANSACTIONS and not
        // STAFF. See the service for why that boundary is worth the injection.
        private readonly WorkAttributionService $attribution,
    ) {}

    /**
     * POST /api/v1/transactions/preview
     *
     * What a bill will come to, before anybody commits to it — the brief's §12
     * confirmation screen.
     *
     * Its own verb rather than a flag on `sale`, and for the reason the
     * opening-balance module gives `preview` a route of its own: committing to
     * the ledger must never be something that happened because a boolean was
     * left out. Nothing here writes, and the request has no `post` field to
     * forget.
     *
     * WRITE:TRANSACTIONS rather than READ, although it writes nothing. The
     * question it answers — "what would this bill be worth" — is one only the
     * person about to write the bill has any use for, and gating it on READ
     * would hand a pricing calculator to everybody who may look at the day book.
     */
    public function previewBill(PreviewBillRequest $request): JsonResponse
    {
        return ApiResponse::success(
            $this->preview->preview($request->type(), $request->payload()),
        );
    }

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

            // Where a bill stands against what has been paid on it — M16, and
            // the vocabulary of the bills list's status filter. The tone travels
            // with the value so a badge's colour is decided once, on the server,
            // rather than in each screen that renders one (§38).
            'payment_statuses' => array_map(fn (PaymentStatus $status) => [
                'value' => $status->value,
                'label' => $status->label(),
                'tone' => $status->tone(),
                'is_outstanding' => $status->isOutstanding(),
            ], PaymentStatus::cases()),

            /*
            | The trades a sale asks about by name, and who can fill each — M22.
            |
            | Served from the transactions grant on purpose. Only OWNER holds
            | STAFF, deliberately, because it guards what people are paid — so a
            | sale form that fetched its pickers from `/staff` would 403 for the
            | counter clerk who is its main user. Names and ids only reach here;
            | the rate, the basis and the joining date stay behind the staff
            | grant where they belong. See WorkAttributionService.
            |
            | Empty for a workshop that has ticked no trade, and the form then
            | paints nothing.
            */
            'staff_slots' => $this->attribution->slots(),
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

        // Who did the work — M22. Attached for a sale at any status, including a
        // draft: a document parked half-written keeps the two names that were
        // picked, or reopening it would silently drop them.
        $this->attribution->attachTo([$record]);

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

        return $this->respondToCreate(
            $transaction,
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

        // Which invoices the money settled — M16. Only once it is actually in
        // the books: a draft has moved nothing, so there is nothing yet to point
        // at anything.
        //
        // Applied even when the request named no bills, in which case the money
        // goes against the party's open bills oldest first. That default is the
        // difference between a receipt that reduces a balance and one that also
        // marks INV/26-27/1012 as paid, and asking every counter clerk to
        // nominate invoices before they can take a ₹500 note would be the wrong
        // trade.
        // Not re-run for a repeat submission: the first attempt already decided
        // which invoices this money settled, and doing it again would replace
        // that decision with a freshly computed one — quietly moving an
        // allocation the operator may have corrected in between.
        $allocations = $transaction->isDraft() || ! $transaction->wasRecentlyCreated
            ? new Collection
            : $this->settlements->allocate($transaction, $request->allocations());

        return $this->respondToCreate(
            $transaction,
            $transaction->isDraft()
                ? 'Draft saved.'
                : sprintf('%s recorded.', $type->label()),
            $allocations->isEmpty() ? [] : ['allocations' => $this->allocationMeta($transaction)],
        );
    }

    /**
     * POST /api/v1/transactions/{transaction}/return
     *
     * Take part of a bill back — M18, and the brief's scenarios 5 and 6.
     *
     * Its own route rather than a flag on `reverse`, because the two are
     * different acts. A reversal says the document was a mistake and cancels it
     * whole; a return says three of the four bearings are still the customer's
     * and one is back on the shelf. The invoice stays posted, stays true, and can
     * be returned against again next week.
     *
     * WRITE, because it writes a new transaction — the credit note — exactly as
     * a reversal does.
     */
    public function storeReturn(StoreReturnRequest $request, int $transaction): JsonResponse
    {
        $bill = $this->transactions->find($transaction);

        $return = $this->returns->returnAgainst(
            $bill,
            $request->lines(),
            $request->input('date'),
            $request->input('notes'),
            $request->user(),
            $request->clientRef(),
        );

        return $this->respondToCreate(
            $return,
            sprintf('%s recorded.', $return->type->label()),
        );
    }

    /**
     * GET /api/v1/transactions/{transaction}/returnable
     *
     * Each line of a bill with what has already come back and what still could —
     * what a returns screen renders, and what stops somebody being told "only 2
     * can be returned" only after they have typed 3.
     */
    public function returnable(int $transaction): JsonResponse
    {
        $bill = $this->transactions->find($transaction);

        return ApiResponse::success(
            array_map(fn (array $row) => [
                'line_no' => (int) $row['line']->line_no,
                'description' => $row['line']->description,
                'unit' => $row['line']->unit->value,
                'unit_symbol' => $row['line']->unit->symbol(),
                'billed' => $row['line']->quantityValue()->amount(),
                'returned' => $row['returned']->amount(),
                'remaining' => $row['remaining']->amount(),
                'unit_price' => $row['line']->unitPriceMoney()->amount(),
            ], $this->returns->returnableLines($bill)),
            null,
            200,
            ['returns' => $this->returns->returnsFor($bill)->map(fn (Transaction $note) => [
                'id' => (int) $note->id,
                'doc_no' => $note->doc_no,
                'date' => $note->date->toDateString(),
                'total' => $note->totalMoney()->amount(),
                'status' => $note->status->value,
            ])->all()],
        );
    }

    /**
     * POST /api/v1/transactions/{transaction}/allocate
     *
     * Re-point a posted receipt or payment at the bills it settles.
     *
     * Its own route rather than part of the edit, because it is a different kind
     * of act. Everything else about a posted transaction is immutable — a wrong
     * amount is corrected by reversal, which leaves both entries on the record —
     * but an allocation writes nothing to the ledger at all. The money arrived
     * where it arrived; this only records which invoice the workshop considers it
     * to have discharged, and getting that wrong is a clerical matter rather than
     * an accounting one.
     */
    public function allocate(AllocateSettlementRequest $request, int $transaction): JsonResponse
    {
        $settlement = $this->transactions->find($transaction);

        $this->settlements->allocate($settlement, $request->allocations());

        return ApiResponse::success(
            $this->allocationMeta($settlement),
            'Payment allocated.',
        );
    }

    /**
     * GET /api/v1/transactions/{transaction}/open-bills
     *
     * What this receipt could still be pointed at: the party's unsettled bills,
     * oldest first, each with what is left owing on it.
     *
     * The list a "which invoices is this for?" picker renders, and the reason it
     * is a server answer rather than a client filter over the transaction list —
     * what is left owing is derived from two tables the client does not have.
     */
    public function openBills(int $transaction): JsonResponse
    {
        $settlement = $this->transactions->find($transaction);

        return ApiResponse::success(
            array_map(fn (array $open) => [
                'id' => (int) $open['transaction']->id,
                'doc_no' => $open['transaction']->doc_no,
                'date' => $open['transaction']->date->toDateString(),
                'total' => $open['transaction']->totalMoney()->amount(),
                'due' => $open['due']->amount(),
            ], $this->settlements->openBillsFor($settlement)),
            null,
            200,
            ['unallocated' => $this->settlements->unallocated($settlement)->amount()],
        );
    }

    /**
     * The response to an attempt to create a document — 201 for a new one, 200
     * for a repeat.
     *
     * The distinction is the whole of the brief's §28 as a client sees it. A
     * clerk who tapped **Save** twice gets the bill back rather than an error,
     * because they did nothing wrong; the status code is what tells a client
     * whether to celebrate or to quietly carry on. See
     * {@see \App\Services\Accounting\TransactionService::create()}.
     *
     * @param  array<string, mixed>  $meta
     */
    private function respondToCreate(Transaction $transaction, string $message, array $meta = []): JsonResponse
    {
        if ($transaction->wasRecentlyCreated) {
            return ApiResponse::created(new TransactionResource($transaction), $message, $meta);
        }

        return ApiResponse::success(
            new TransactionResource($transaction),
            'Already saved — this is the document your first attempt created.',
            200,
            $meta,
        );
    }

    /**
     * What a settlement has been pointed at, and what of it is still spare.
     *
     * @return array<string, mixed>
     */
    private function allocationMeta(Transaction $settlement): array
    {
        return [
            'unallocated' => $this->settlements->unallocated($settlement)->amount(),
            'bills' => $this->settlements->allocationsOf($settlement),
        ];
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
        $payload = $request->payload();

        /*
        | The document and who did the work commit together, or neither does.
        |
        | The attribution is not a financial fact and could in principle be
        | written afterwards — but a 422 from it after the invoice had already
        | committed would leave the clerk looking at an error beside a posted
        | bill, with no way to tell that the bill went through. One transaction
        | means the refusal is about a document that does not exist yet, which is
        | the only version of it somebody can act on.
        |
        | `postComposed` opens its own transaction; this becomes the outer one and
        | that one a savepoint. The extra work inside the wrapper is two small
        | inserts.
        */
        $transaction = DB::transaction(function () use ($type, $payload, $request) {
            $transaction = $this->transactions->create($type, $payload, $request->user());

            /*
            | Only on a document this attempt actually wrote — M17.
            |
            | A retry after a timeout is answered with the *first* attempt's
            | invoice, and syncing then would quietly overwrite a correction
            | somebody had already made to it with whatever stale names the
            | retrying tab was still holding. `wasRecentlyCreated` is the same
            | flag `respondToCreate` reads to tell the two apart.
            */
            if ($transaction->wasRecentlyCreated && ($payload['staff'] ?? []) !== []) {
                $this->attribution->sync($transaction, $payload['staff']);
            }

            return $transaction;
        });

        $this->attribution->attachTo([$transaction]);

        // Warnings, not errors, and returned on the same response that confirms
        // the posting. Selling below cost is a real decision and so is billing
        // something the shelf says is not there — both post, and both have to be
        // put in front of somebody while they are still looking at the bill.
        $warnings = $transaction->isDraft() ? [] : $this->bills->warningsFor($transaction);

        return $this->respondToCreate(
            $transaction,
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

        return $this->respondToCreate(
            $transaction,
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

        return $this->respondToCreate(
            $transaction,
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
            $request->boolean('acknowledge_negative_stock'),
        );

        return ApiResponse::created(
            new TransactionResource($reversal),
            'Reversing entry posted.'
        );
    }

    /**
     * POST /api/v1/transactions/{transaction}/revise
     *
     * Correct a posted purchase bill: the original is reversed and the corrected
     * document posted in its place, as one act. Both stay on the record — this
     * is not an in-place edit, and a posted transaction is still immutable.
     *
     * Takes the same payload a new purchase does, because it *is* a new purchase
     * — which is what stops the corrected document being validated any less
     * strictly than the one it replaces.
     */
    public function revise(StoreBillRequest $request, int $transaction): JsonResponse
    {
        $payload = $request->payload();

        $revision = DB::transaction(function () use ($transaction, $payload, $request) {
            $revision = $this->transactions->revise(
                $transaction,
                // Always posted. A correction parked as a draft would leave the
                // original reversed and its replacement nowhere, which is a worse
                // record than the mistake being corrected.
                ['post' => true] + $payload,
                $request->user(),
                $request->boolean('acknowledge_negative_stock'),
            );

            /*
            | The replacement carries the names the form was holding — M22.
            |
            | Not copied from the original, and the difference matters: a revision
            | is a *new* document, and the form it was raised from has had the
            | attribution on screen the whole time. Copying instead would mean a
            | correction that deliberately changed the fitter got the old one
            | back, silently, on the one path where somebody was paying attention.
            |
            | The reversed original keeps its own rows and stops counting, because
            | the work report ignores anything reversed.
            */
            if ($revision->wasRecentlyCreated && ($payload['staff'] ?? []) !== []) {
                $this->attribution->sync($revision, $payload['staff']);
            }

            return $revision;
        });

        $this->attribution->attachTo([$revision]);

        return $this->respondToCreate(
            $revision,
            'Corrected. The original was reversed and this replaces it.',
            ($warnings = $this->bills->warningsFor($revision)) === [] ? [] : ['warnings' => $warnings],
        );
    }

    /**
     * PATCH /api/v1/transactions/{transaction}/staff
     *
     * Correct who did the work on a sale — M22.
     *
     * The one write in this controller that reaches a posted document, and the
     * reason it is allowed is that it changes no figure on one. A mis-picked
     * fitter is a wrong label on a right invoice; correcting it through
     * `revise` would reverse and reissue a document the customer already holds,
     * and on a sale that is not merely heavy — the engine refuses it outright
     * where the weighted average has moved, with no acknowledgement path. So the
     * choice was between an editable label and a permanently wrong record.
     *
     * Every change is on the audit trail, which is the whole safeguard: see
     * {@see \App\Models\TransactionStaff}.
     */
    public function updateStaff(UpdateTransactionStaffRequest $request, int $transaction): JsonResponse
    {
        $record = $this->transactions->find($transaction);

        $this->attribution->sync($record, $request->payload());
        $this->attribution->attachTo([$record]);

        return ApiResponse::success(
            new TransactionResource($record),
            'Updated who did the work.',
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
