<?php

namespace App\Http\Controllers;

use App\Repositories\Contracts\TenantRepositoryInterface;
use App\Repositories\Contracts\TransactionRepositoryInterface;
use App\Services\Accounting\InvoiceDocumentService;
use App\Services\Accounting\InvoiceShareService;
use App\Support\Tenancy\TenantContext;
use Illuminate\Http\Response as HttpResponse;
use Symfony\Component\HttpFoundation\Response;

/**
 * One invoice, read by the customer it was written for — no account, no login.
 *
 * ## The token is the credential, and tenancy comes *from* it
 *
 * Nothing on this request says which workshop is being asked for. The token
 * does, and that makes it the same shape of problem the authentication path
 * solves: a credential has to be resolved before the identity it carries
 * exists. So the lookup runs unscoped — reason 1 in
 * {@see TenantContext::runWithoutScope()} — and it is the *only* unscoped read
 * on this path.
 *
 * Everything after it runs inside {@see TenantContext::runFor()} for the tenant
 * the token named. That is not ceremony: it means a share row that somehow
 * pointed at another workshop's transaction would resolve to nothing rather than
 * rendering it, because the scoped read simply would not find it.
 *
 * ## Why the page is rendered rather than fetched
 *
 * The payload is embedded in the markup, so the customer's phone makes one
 * request and no API call. That matters more here than anywhere else in the
 * product: this is the one page opened by somebody on a shop's wifi, on a
 * borrowed handset, from a WhatsApp thread, and a second round trip is a second
 * chance for it to show nothing. The renderer is still the shared component —
 * see `components/invoice-document.js`, which paints this page and the
 * workshop's own print copy from the identical array.
 *
 * ## What a bad token gets
 *
 * 404, and the same 404 whether the token never existed, was revoked, or points
 * at a document that has since been reversed. Distinguishing them would confirm
 * to somebody guessing that a guessed token had once been real.
 *
 * ## Why it is kept out of search engines
 *
 * A customer's name, address, GSTIN and what they were charged. The link is
 * unguessable, but a customer who pastes it somewhere public would otherwise
 * have their invoice indexed — and the workshop would be the one asked why.
 * `noindex` goes on the response as a header as well as in the markup, so it
 * still holds for a crawler that only fetched the headers.
 */
class PublicInvoiceController extends Controller
{
    public function __construct(
        private readonly TenantContext $context,
        private readonly InvoiceShareService $shares,
        private readonly InvoiceDocumentService $documents,
        private readonly TransactionRepositoryInterface $transactions,
        private readonly TenantRepositoryInterface $tenants,
    ) {}

    public function __invoke(string $token): HttpResponse
    {
        $share = $this->shares->resolve($token);

        abort_if($share === null, Response::HTTP_NOT_FOUND);

        $invoice = $this->context->runFor($share->tenant_id, function () use ($share) {
            $transaction = $this->transactions->findById((int) $share->transaction_id);

            // Asked again on every read, not merely when the link was issued.
            // An invoice reversed after it was shared stops being readable the
            // moment it is reversed, without anything having to remember to
            // revoke the link. See InvoiceShareService::isShareable().
            abort_if(
                $transaction === null || ! $this->shares->isShareable($transaction),
                Response::HTTP_NOT_FOUND,
            );

            return $this->documents->for(
                $transaction,
                $this->tenants->findById((int) $share->tenant_id),
            );
        });

        return response()
            ->view('invoices.public', ['invoice' => $invoice])
            ->header('X-Robots-Tag', 'noindex, nofollow, noarchive');
    }
}
