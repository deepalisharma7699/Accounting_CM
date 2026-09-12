<?php

namespace App\Http\Controllers\Api\V1;

use App\Enums\WorkshopJobStatus;
use App\Http\Controllers\Controller;
use App\Http\Requests\WorkshopJob\BillJobRequest;
use App\Http\Requests\WorkshopJob\IndexJobRequest;
use App\Http\Requests\WorkshopJob\SaveEstimateRequest;
use App\Http\Requests\WorkshopJob\StoreJobPartRequest;
use App\Http\Requests\WorkshopJob\StoreJobRequest;
use App\Http\Requests\WorkshopJob\UpdateJobRequest;
use App\Http\Requests\WorkshopJob\UpdateJobStatusRequest;
use App\Http\Resources\TransactionResource;
use App\Models\ItemCategory;
use App\Repositories\Contracts\ItemCategoryRepositoryInterface;
use App\Http\Resources\WorkshopJobResource;
use App\Services\Accounting\BillService;
use App\Services\Staff\WorkAttributionService;
use App\Services\Workshop\JobService;
use App\Support\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/**
 * Workshop jobs — M19, and the brief's §16 to §18.
 *
 * The motor on the bench, from booked in to billed. One permission guards the
 * lot: WORKSHOP_JOBS, held by both the owner and the data-entry user, because
 * booking a motor in and writing parts onto it *is* the day job of whoever is
 * standing at the counter.
 *
 * Note what `bill()` does not do. It does not compose a sale — it hands the job
 * to {@see JobService}, which builds the payload `POST /transactions/sale`
 * accepts and posts it through the same engine the counter uses. That is why the
 * route needs WRITE:TRANSACTIONS as well: raising an invoice is capturing a
 * business event, whichever screen it was reached from, and a jobs grant that
 * quietly conferred the ability to post to the ledger would be a hole in the
 * permission model rather than a convenience.
 */
class WorkshopJobController extends Controller
{
    public function __construct(
        private readonly JobService $jobs,
        private readonly ItemCategoryRepositoryInterface $categories,
        private readonly BillService $bills,
        private readonly WorkAttributionService $attribution,
    ) {}

    /* ---------------------------------------------------------------------
     | Reading
     |-------------------------------------------------------------------- */

    /**
     * GET /api/v1/workshop-jobs
     */
    public function index(IndexJobRequest $request): JsonResponse
    {
        return ApiResponse::paginated(
            $this->jobs->paginate($request->filters(), $request->perPage()),
            WorkshopJobResource::class,
        );
    }

    /**
     * GET /api/v1/workshop-jobs/meta
     *
     * The statuses, what may follow each, which of them a bill can be raised
     * from, and **what kinds of thing this workshop takes in** — so a client
     * builds its pipeline control, its filters and its intake form from the
     * server's answer rather than from a hard-coded copy that drifts the day a
     * state or a category is added.
     */
    public function meta(): JsonResponse
    {
        return ApiResponse::success([
            'statuses' => WorkshopJobStatus::catalogue(),
            // The tab badges, in the same call. One grouped query rather than a
            // count request per tab — the same shape as `transactions/counts`.
            'counts' => $this->jobs->countsByStatus(),
            'kinds' => $this->kinds(),
        ]);
    }

    /**
     * What can be booked in, and what to ask about each — the catalogue's own
     * vocabulary, published where the bench can read it.
     *
     * ## Why it is here and not fetched from `GET /items/meta`
     *
     * Because that route is behind `READ:ITEMS` and this one is behind
     * `READ:WORKSHOP_JOBS`, and the person booking a motor in is exactly the
     * person who may hold the second and not the first. Fetching the intake
     * form's fields from the items route would 403 the form for its main user —
     * the same trap M22's attribution pickers avoid by riding on
     * `GET /transactions/meta` rather than on `/staff`.
     *
     * ## Why the payload is `/items/meta`'s shape
     *
     * Because one renderer draws both — `components/attribute-fields.js` — and a
     * second shape would be a second contract for it to go stale against (§4.4).
     *
     * ## Why `holds_stock` is the filter
     *
     * A job is a physical object on a bench. In this application a category that
     * holds no stock is one whose things are *produced at the moment they are
     * sold* — an hour of rewinding — and nobody wheels one of those through a
     * door. That is a property of the category rather than a flag invented for
     * this module, which is why there is no `repairable` column to keep in step
     * with it. Everything else a workshop has defined is offered, including
     * categories it has never stocked a product under: describing what comes in
     * for repair is exactly what a category is for.
     *
     * @return array<int, array<string, mixed>>
     */
    private function kinds(): array
    {
        return $this->categories->all(['is_active' => true])
            ->filter(fn (ItemCategory $category) => (bool) $category->holds_stock)
            ->map(fn (ItemCategory $category) => [
                'value' => (string) $category->id,
                'id' => (int) $category->id,
                'label' => $category->name,
                'description' => $category->description,
                // Keyed by attribute, with the label, the data type, the fixed
                // values where a fixed set exists and the unit to print beside
                // the box. A subcategory's set already has its parent's folded
                // in.
                'attributes' => (object) $category->attributeSchema(),
            ])->values()->all();
    }

    /**
     * GET /api/v1/workshop-jobs/{job}
     */
    public function show(int $job): JsonResponse
    {
        return ApiResponse::success(new WorkshopJobResource($this->jobs->find($job)));
    }

    /**
     * GET /api/v1/workshop-jobs/{job}/bill-preview
     *
     * The payload the counter screen opens pre-filled — `billPayloadFor()`,
     * unposted.
     *
     * A read rather than a step in posting, and that is the whole reason it
     * exists: "Generate bill" should land the operator on the bill counter with
     * the job's parts already on it, so a price can be argued about *before*
     * anything reaches the ledger. What they are looking at is the same structure
     * {@see \App\Services\Workshop\JobService::bill()} would post, not a
     * rendering of it.
     */
    public function billPreview(int $job): JsonResponse
    {
        $record = $this->jobs->find($job);

        return ApiResponse::success(
            $this->jobs->billPayloadFor($record),
            null,
            200,
            [
                'job' => [
                    'id' => $record->id,
                    'job_no' => $record->job_no,
                    'equipment' => $record->equipmentLabel(),
                    'status' => $record->status->value,
                    'is_billable' => $record->isBillable(),
                ],
            ],
        );
    }

    /* ---------------------------------------------------------------------
     | Writing
     |-------------------------------------------------------------------- */

    /**
     * POST /api/v1/workshop-jobs
     */
    public function store(StoreJobRequest $request): JsonResponse
    {
        return ApiResponse::created(
            new WorkshopJobResource($this->jobs->create($request->payload(), $request->user())),
            'Job booked in.',
        );
    }

    /**
     * PATCH /api/v1/workshop-jobs/{job}
     *
     * The motor and the complaint. Not the status, which has a verb of its own,
     * and not the customer — see {@see UpdateJobRequest}.
     */
    public function update(UpdateJobRequest $request, int $job): JsonResponse
    {
        return ApiResponse::success(
            new WorkshopJobResource($this->jobs->update($job, $request->payload())),
            'Job updated.',
        );
    }

    /**
     * PUT /api/v1/workshop-jobs/{job}/status
     *
     * A pipeline move, refused where the pipeline does not allow it.
     */
    public function updateStatus(UpdateJobStatusRequest $request, int $job): JsonResponse
    {
        $record = $this->jobs->advance($job, $request->status(), $request->notes());

        return ApiResponse::success(
            new WorkshopJobResource($record),
            sprintf('Job moved to %s.', strtolower($record->status->label())),
        );
    }

    /**
     * DELETE /api/v1/workshop-jobs/{job}
     *
     * Only ever reaches a job nothing has been billed against. Anything with a
     * document pointing at it is refused with `JOB_IN_USE` and cancelled instead
     * — the same rule an account, a party and an item follow.
     */
    public function destroy(int $job): JsonResponse
    {
        $this->jobs->delete($job);

        return ApiResponse::message('Job deleted.');
    }

    /* ---------------------------------------------------------------------
     | Parts — §17
     |-------------------------------------------------------------------- */

    /**
     * POST /api/v1/workshop-jobs/{job}/parts
     *
     * Writes a note about what will be billed. Moves no stock — see decision D2
     * and the `workshop_job_parts` migration.
     */
    public function storePart(StoreJobPartRequest $request, int $job): JsonResponse
    {
        return ApiResponse::created(
            new WorkshopJobResource($this->jobs->addPart($job, $request->payload())),
            'Part added to the job.',
        );
    }

    /**
     * DELETE /api/v1/workshop-jobs/{job}/parts/{part}
     *
     * Refused once the part has been billed: the customer is holding an invoice
     * that says it was fitted, and the correction for that is a credit note.
     */
    public function destroyPart(int $job, int $part): JsonResponse
    {
        return ApiResponse::success(
            new WorkshopJobResource($this->jobs->removePart($job, $part)),
            'Part removed.',
        );
    }

    /* ---------------------------------------------------------------------
     | The estimate — §18
     |-------------------------------------------------------------------- */

    /**
     * PUT /api/v1/workshop-jobs/{job}/estimate
     *
     * Replaces the quotation whole, and clears any approval on it — a customer
     * who agreed to a figure has not agreed to a different one.
     */
    public function saveEstimate(SaveEstimateRequest $request, int $job): JsonResponse
    {
        return ApiResponse::success(
            new WorkshopJobResource($this->jobs->saveEstimate($job, $request->lines(), $request->notes())),
            'Estimate saved.',
        );
    }

    /**
     * POST /api/v1/workshop-jobs/{job}/estimate/approve
     *
     * Records that the customer said yes — or, with `{"approved": false}`, that
     * they changed their mind.
     *
     * Its own verb rather than a field on the estimate, because they are separate
     * events that often happen days apart, and because an approval arriving with
     * the quotation would mean nobody was ever asked.
     */
    public function approveEstimate(Request $request, int $job): JsonResponse
    {
        $approved = ! $request->has('approved') || $request->boolean('approved');

        return ApiResponse::success(
            new WorkshopJobResource($this->jobs->approveEstimate($job, $approved)),
            $approved ? 'Estimate approved.' : 'Approval withdrawn.',
        );
    }

    /**
     * POST /api/v1/workshop-jobs/{job}/estimate/apply
     *
     * Copy the quotation onto the job as parts — §18's conversion.
     *
     * Additive rather than replacing: what was quoted is a record of what was
     * expected, and the parts are a record of what was actually fitted.
     */
    public function applyEstimate(int $job): JsonResponse
    {
        return ApiResponse::success(
            new WorkshopJobResource($this->jobs->partsFromEstimate($job)),
            'Estimate copied onto the job.',
        );
    }

    /* ---------------------------------------------------------------------
     | Billing
     |-------------------------------------------------------------------- */

    /**
     * POST /api/v1/workshop-jobs/{job}/bill
     *
     * Raises the invoice through the ordinary sale path, so the tax, the stock
     * issue, the cost of goods sold, the numbering and the duplicate protection
     * are all the engine's.
     *
     * Answers with the transaction rather than the job, because the invoice is
     * what the operator needs next — to print it, or to take the money against
     * it. The job is a request away and has not changed except in what is now
     * marked as billed.
     */
    public function bill(BillJobRequest $request, int $job): JsonResponse
    {
        $overrides = $request->overrides();

        /*
        | The invoice and who did the work commit together, or neither does -
        | the same wrapper `TransactionController::store()` puts round a counter
        | sale, and for the same reason: a 422 from the attribution after the
        | bill had already committed would leave the operator looking at an
        | error beside a posted invoice with no way to tell that it went
        | through. JobService::bill() opens its own transaction, which becomes a
        | savepoint inside this one.
        |
        | Only on a document this attempt actually wrote. A retry after a
        | timeout is answered with the first attempt's invoice (M17), and
        | syncing then would overwrite a correction somebody had already made
        | with whatever stale names the retrying tab was still holding.
        */
        $bill = DB::transaction(function () use ($job, $overrides, $request) {
            $bill = $this->jobs->bill($job, $overrides, $request->user());

            if ($bill->wasRecentlyCreated && ($overrides['staff'] ?? []) !== []) {
                $this->attribution->sync($bill, $overrides['staff']);
            }

            return $bill;
        });

        $this->attribution->attachTo([$bill]);

        $meta = array_filter([
            'tax' => $this->bills->taxSummaryFor($bill),
            // Ridden back on the same response that confirms the posting, and
            // shown as well as it rather than instead of it: the bill did post,
            // and somebody still needs to look at what it said.
            'warnings' => $this->bills->warningsFor($bill),
        ], fn ($value) => $value !== null && $value !== []);

        return ApiResponse::created(
            new TransactionResource($bill),
            sprintf('Invoice %s raised.', $bill->doc_no ?? "#{$bill->id}"),
            $meta,
        );
    }
}
