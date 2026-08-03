<?php

use App\Http\Controllers\Api\V1\AttachmentController;
use App\Http\Controllers\Api\V1\AuditLogController;
use App\Http\Controllers\Api\V1\AuthController;
use App\Http\Controllers\Api\V1\ChartOfAccountController;
use App\Http\Controllers\Api\V1\ItemController;
use App\Http\Controllers\Api\V1\JobRunController;
use App\Http\Controllers\Api\V1\LedgerController;
use App\Http\Controllers\Api\V1\OpeningBalanceController;
use App\Http\Controllers\Api\V1\PartyController;
use App\Http\Controllers\Api\V1\PermissionController;
use App\Http\Controllers\Api\V1\ReportController;
use App\Http\Controllers\Api\V1\RoleController;
use App\Http\Controllers\Api\V1\StockController;
use App\Http\Controllers\Api\V1\TenantController;
use App\Http\Controllers\Api\V1\TransactionController;
use App\Http\Controllers\Api\V1\UserController;
use App\Http\Controllers\Api\V1\WorkspaceController;
use App\Http\Middleware\EnsurePermission;
use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| Authentication & User Management API (v1)
|--------------------------------------------------------------------------
|
| Guards, from outermost in:
|   throttle:*   — request rate limiting (see AuthModuleServiceProvider)
|   auth.jwt     — verifies the Bearer access token   (authGuard)
|   permission:* — checks the required grant(s)       (permissionGuard)
|
*/

Route::prefix('v1')->group(function () {

    /*
    | Public auth endpoints. `refresh` and `logout` are unauthenticated by
    | design: they are driven by the refresh cookie, which is exactly what a
    | client presents when its access token has already expired.
    */
    Route::prefix('auth')->group(function () {
        Route::post('register', [AuthController::class, 'register'])
            ->middleware('throttle:auth-register');

        Route::post('login', [AuthController::class, 'login'])
            ->middleware('throttle:auth-login');

        Route::post('refresh', [AuthController::class, 'refresh'])
            ->middleware('throttle:auth-refresh');

        Route::post('logout', [AuthController::class, 'logout']);

        Route::middleware('auth.jwt')->group(function () {
            Route::get('me', [AuthController::class, 'me']);
            Route::post('logout-all', [AuthController::class, 'logoutAll']);
        });
    });

    Route::middleware(['auth.jwt', 'throttle:api'])->group(function () {

        /*
        | Permission catalogue (read-only: permissions are defined by the
        | application and seeded, never created through the API).
        */
        Route::get('permissions', [PermissionController::class, 'index'])
            ->middleware(EnsurePermission::using('READ', 'PERMISSIONS'));

        /*
        | Custom role management.
        */
        Route::prefix('roles')->group(function () {
            Route::get('/', [RoleController::class, 'index'])
                ->middleware('permission:READ,ROLES');

            Route::get('{role}', [RoleController::class, 'show'])
                ->whereNumber('role')
                ->middleware('permission:READ,ROLES');

            Route::post('/', [RoleController::class, 'store'])
                ->middleware('permission:WRITE,ROLES');

            Route::patch('{role}', [RoleController::class, 'update'])
                ->whereNumber('role')
                ->middleware('permission:UPDATE,ROLES');

            Route::delete('{role}', [RoleController::class, 'destroy'])
                ->whereNumber('role')
                ->middleware('permission:DELETE,ROLES');

            // Changing a role's grants requires authority over both roles and
            // the permission catalogue — hence two required permissions.
            Route::put('{role}/permissions', [RoleController::class, 'syncPermissions'])
                ->whereNumber('role')
                ->middleware('permission:UPDATE:ROLES,READ:PERMISSIONS');
        });

        /*
        | The caller's own workshop. No {id} — the workshop comes from the
        | tenant context the auth guard established, so there is nothing here
        | to tamper with. An owner holds WORKSPACE; they never hold TENANTS.
        */
        Route::get('workspace', [WorkspaceController::class, 'show'])
            ->middleware('permission:READ,WORKSPACE');

        Route::patch('workspace', [WorkspaceController::class, 'update'])
            ->middleware('permission:UPDATE,WORKSPACE');

        /*
        | Tenant (workshop) administration — a platform surface, not a
        | workshop one. TENANTS grants authority *over* tenants, so only the
        | ADMIN system role holds it; a workshop owner has no route in here.
        */
        Route::prefix('tenants')->group(function () {
            Route::get('/', [TenantController::class, 'index'])
                ->middleware('permission:READ,TENANTS');

            Route::get('{tenant}', [TenantController::class, 'show'])
                ->whereNumber('tenant')
                ->middleware('permission:READ,TENANTS');

            // Provisioning may create the owner user too, hence authority over
            // both resources.
            Route::post('/', [TenantController::class, 'store'])
                ->middleware('permission:WRITE:TENANTS,WRITE:USERS');

            Route::patch('{tenant}', [TenantController::class, 'update'])
                ->whereNumber('tenant')
                ->middleware('permission:UPDATE,TENANTS');

            Route::put('{tenant}/status', [TenantController::class, 'updateStatus'])
                ->whereNumber('tenant')
                ->middleware('permission:UPDATE,TENANTS');

            Route::delete('{tenant}', [TenantController::class, 'destroy'])
                ->whereNumber('tenant')
                ->middleware('permission:DELETE,TENANTS');
        });

        /*
        | Chart of accounts. Tenant-owned: the global scope on ChartOfAccount
        | filters every query, so there is nothing tenant-shaped to guard here.
        |
        | No DELETE route by design — accounts are archived via
        | `PATCH {id} {"is_active": false}`, because an account that has been
        | posted to must survive or its journal entries lose their name.
        */
        Route::prefix('accounts')->group(function () {
            // Declared before the {account} route, or "types" would be parsed
            // as an id.
            Route::get('types', [ChartOfAccountController::class, 'types'])
                ->middleware('permission:READ,ACCOUNTS');

            Route::get('/', [ChartOfAccountController::class, 'index'])
                ->middleware('permission:READ,ACCOUNTS');

            Route::get('{account}', [ChartOfAccountController::class, 'show'])
                ->whereNumber('account')
                ->middleware('permission:READ,ACCOUNTS');

            Route::post('/', [ChartOfAccountController::class, 'store'])
                ->middleware('permission:WRITE,ACCOUNTS');

            Route::patch('{account}', [ChartOfAccountController::class, 'update'])
                ->whereNumber('account')
                ->middleware('permission:UPDATE,ACCOUNTS');
        });

        /*
        | Parties: customers, suppliers, and the many who are both.
        |
        | Two permissions meet here. PARTIES is authority over the record — who
        | exists and how they are reached — and DATA_ENTRY holds READ and WRITE
        | so a walk-in customer can be captured at the counter. LEDGER is
        | authority over the money, which is why `{id}/ledger` is guarded by it
        | and not by PARTIES: adding a customer and reading what they owe are
        | different things.
        |
        | DELETE only ever reaches a party nothing points at. Once they appear
        | on a transaction they are archived instead, via
        | `PATCH {id} {"is_active": false}` — the same rule as an account, for
        | the same reason: their ledger lines would lose the name that explains
        | them.
        */
        Route::prefix('parties')->group(function () {
            // Declared before {party}, or "meta" is parsed as an id.
            Route::get('meta', [PartyController::class, 'meta'])
                ->middleware('permission:READ,PARTIES');

            Route::get('/', [PartyController::class, 'index'])
                ->middleware('permission:READ,PARTIES');

            Route::get('{party}', [PartyController::class, 'show'])
                ->whereNumber('party')
                ->middleware('permission:READ,PARTIES');

            // The statement. Reading a party's position is a financial read,
            // so it needs both: knowing who they are, and being allowed to see
            // the books.
            Route::get('{party}/ledger', [PartyController::class, 'ledger'])
                ->whereNumber('party')
                ->middleware('permission:READ:PARTIES,READ:LEDGER');

            Route::post('/', [PartyController::class, 'store'])
                ->middleware('permission:WRITE,PARTIES');

            Route::patch('{party}', [PartyController::class, 'update'])
                ->whereNumber('party')
                ->middleware('permission:UPDATE,PARTIES');

            Route::delete('{party}', [PartyController::class, 'destroy'])
                ->whereNumber('party')
                ->middleware('permission:DELETE,PARTIES');
        });

        /*
        | The catalogue: item families and their variants.
        |
        | Master data like ACCOUNTS and PARTIES. DATA_ENTRY holds READ and WRITE
        | for the same reason it holds them on parties — a part nobody has
        | recorded yet turns up as often as a new customer, and a clerk who had
        | to fetch the owner to add a bearing would bill it as something else.
        |
        | Variants are nested rather than top-level: one has no meaning apart
        | from its family, and the family is what decides which attributes it
        | must carry.
        |
        | DELETE only ever reaches an item nothing points at. Once anything
        | references it, it is archived instead via
        | `PATCH {id} {"is_active": false}` — the same rule as an account and a
        | party, for the same reason: its bill lines would lose the name that
        | explains them.
        */
        Route::prefix('items')->group(function () {
            // Declared before {item}, or "meta" is parsed as an id.
            Route::get('meta', [ItemController::class, 'meta'])
                ->middleware('permission:READ,ITEMS');

            Route::get('/', [ItemController::class, 'index'])
                ->middleware('permission:READ,ITEMS');

            Route::get('{item}', [ItemController::class, 'show'])
                ->whereNumber('item')
                ->middleware('permission:READ,ITEMS');

            Route::post('/', [ItemController::class, 'store'])
                ->middleware('permission:WRITE,ITEMS');

            Route::patch('{item}', [ItemController::class, 'update'])
                ->whereNumber('item')
                ->middleware('permission:UPDATE,ITEMS');

            Route::delete('{item}', [ItemController::class, 'destroy'])
                ->whereNumber('item')
                ->middleware('permission:DELETE,ITEMS');

            Route::get('{item}/variants', [ItemController::class, 'variants'])
                ->whereNumber('item')
                ->middleware('permission:READ,ITEMS');

            Route::post('{item}/variants', [ItemController::class, 'storeVariant'])
                ->whereNumber('item')
                ->middleware('permission:WRITE,ITEMS');

            Route::patch('{item}/variants/{variant}', [ItemController::class, 'updateVariant'])
                ->whereNumber('item')->whereNumber('variant')
                ->middleware('permission:UPDATE,ITEMS');

            Route::delete('{item}/variants/{variant}', [ItemController::class, 'destroyVariant'])
                ->whereNumber('item')->whereNumber('variant')
                ->middleware('permission:DELETE,ITEMS');
        });

        /*
        | Transactions and the ledger behind them.
        |
        | TRANSACTIONS is authority to capture business events; DATA_ENTRY holds
        | it. LEDGER is authority to read the workshop's whole financial
        | position, and only OWNER holds that — the two are separate on purpose.
        |
        | There is no general PATCH of a posted transaction and no DELETE of
        | one. A draft can be rewritten or discarded because it never reached
        | the ledger; anything in the books is corrected by `reverse`, which
        | leaves both the mistake and the correction on the record.
        */
        Route::prefix('transactions')->group(function () {
            // Before {transaction}, or "meta" is parsed as an id.
            Route::get('meta', [TransactionController::class, 'meta'])
                ->middleware('permission:READ,TRANSACTIONS');

            // Likewise before {transaction}. The type and status breakdown a
            // tabbed listing labels its tabs from — one grouped query rather
            // than a count request per tab.
            Route::get('counts', [TransactionController::class, 'counts'])
                ->middleware('permission:READ,TRANSACTIONS');

            Route::get('/', [TransactionController::class, 'index'])
                ->middleware('permission:READ,TRANSACTIONS');

            Route::get('{transaction}', [TransactionController::class, 'show'])
                ->whereNumber('transaction')
                ->middleware('permission:READ,TRANSACTIONS');

            // Per transaction type, because each has its own payload: a journal
            // carries raw lines, a settlement carries a party and the ways the
            // money moved. One endpoint taking a discriminated union would
            // validate none of them properly.
            Route::post('journal', [TransactionController::class, 'storeJournal'])
                ->middleware('permission:WRITE,TRANSACTIONS');

            // Templates D and E. Two routes rather than one with a direction
            // field: paying a supplier and collecting from a customer are
            // different events, and the URL should say which happened.
            Route::post('payment', [TransactionController::class, 'storePayment'])
                ->middleware('permission:WRITE,TRANSACTIONS');

            Route::post('receipt', [TransactionController::class, 'storeReceipt'])
                ->middleware('permission:WRITE,TRANSACTIONS');

            // Templates A, B and C. Two routes rather than one with a direction
            // field, exactly as payment and receipt are two: selling a motor and
            // buying one in are different events, and the URL should say which
            // happened.
            Route::post('sale', [TransactionController::class, 'storeSale'])
                ->middleware('permission:WRITE,TRANSACTIONS');

            Route::post('purchase', [TransactionController::class, 'storePurchase'])
                ->middleware('permission:WRITE,TRANSACTIONS');

            // Template F. Separate from a purchase because the two are different
            // kinds of money: a purchase is bought to sell or to fit, an expense
            // is what it costs to be open.
            Route::post('expense', [TransactionController::class, 'storeExpense'])
                ->middleware('permission:WRITE,TRANSACTIONS');

            // Template G. WRITE:TRANSACTIONS and nothing else — moving stock is
            // capturing a business event, and there is deliberately no separate
            // "adjust stock" grant, because a grant that could change quantities
            // without writing a transaction is the write path this module exists
            // to not have.
            Route::post('stock-adjustment', [TransactionController::class, 'storeStockAdjustment'])
                ->middleware('permission:WRITE,TRANSACTIONS');

            Route::patch('{transaction}', [TransactionController::class, 'update'])
                ->whereNumber('transaction')
                ->middleware('permission:UPDATE,TRANSACTIONS');

            // Authorising a draft is its own action, so that saving an edit can
            // never commit it to the ledger by accident.
            Route::post('{transaction}/post', [TransactionController::class, 'post'])
                ->whereNumber('transaction')
                ->middleware('permission:UPDATE,TRANSACTIONS');

            // A reversal writes a new transaction, hence WRITE rather than
            // UPDATE — the original is untouched apart from its status.
            Route::post('{transaction}/reverse', [TransactionController::class, 'reverse'])
                ->whereNumber('transaction')
                ->middleware('permission:WRITE,TRANSACTIONS');

            // Drafts only. The model refuses anything with entries.
            Route::delete('{transaction}', [TransactionController::class, 'destroy'])
                ->whereNumber('transaction')
                ->middleware('permission:DELETE,TRANSACTIONS');
        });

        /*
        | Stock — M8. Every one of these is a query over `stock_movements`; no
        | number here is stored anywhere.
        |
        | Read-only, and structurally so: nothing writes to the stock ledger
        | except the posting engine, which is why there is no POST, PATCH or
        | DELETE in this group and why STOCK has only a READ grant. Quantities
        | are moved by posting a transaction — `POST /transactions/stock-
        | adjustment` above, and M9's bills.
        |
        | Gated on STOCK rather than on ITEMS, and DATA_ENTRY holds it: knowing
        | the workshop deals in 5 HP motors is catalogue, and knowing four are on
        | the shelf is position. A clerk billing a bearing has to know whether
        | there is one.
        */
        Route::prefix('stock')->group(function () {
            // Both declared before {variant}-shaped routes, though there is no
            // clash today — the same habit that keeps `accounts/types` working.
            Route::get('meta', [StockController::class, 'meta'])
                ->middleware('permission:READ,STOCK');

            // The money side of this response is gated inside the controller
            // rather than on the route: the stock half is legitimately a
            // data-entry user's, and requiring LEDGER here would take it away to
            // protect one line of it.
            Route::get('summary', [StockController::class, 'summary'])
                ->middleware('permission:READ,STOCK');

            Route::get('/', [StockController::class, 'index'])
                ->middleware('permission:READ,STOCK');

            Route::get('variants/{variant}', [StockController::class, 'card'])
                ->whereNumber('variant')
                ->middleware('permission:READ,STOCK');
        });

        /*
        | Opening balances — M11. What the workshop already had on the day the
        | books opened, declared once.
        |
        | Gated on WRITE:TRANSACTIONS **and** UPDATE:WORKSPACE, which is the
        | interesting part. Posting an opening balance is capturing a business
        | event, so the first is obvious; the second is what keeps it out of a
        | data-entry user's hands. Declaring what the workshop was worth at
        | go-live is a setup act — it belongs beside books_start_date, which is
        | the setting it is dated against — and it is not something a clerk
        | should be able to do while entering the day's takings. Only OWNER holds
        | both.
        |
        | `preview` writes nothing and is a separate verb rather than a flag, so
        | committing a workshop's whole financial history can never be something
        | that happened because a boolean was left out.
        |
        | There is no PATCH and no DELETE. An opening balance that was wrong is
        | corrected the way every posted transaction is: with a reversal, which
        | leaves both the mistake and the correction on the record.
        */
        Route::prefix('opening-balances')->group(function () {
            Route::get('meta', [OpeningBalanceController::class, 'meta'])
                ->middleware('permission:READ,LEDGER');

            Route::get('/', [OpeningBalanceController::class, 'index'])
                ->middleware('permission:READ,LEDGER');

            Route::post('preview', [OpeningBalanceController::class, 'preview'])
                ->middleware('permission:WRITE:TRANSACTIONS,UPDATE:WORKSPACE');

            Route::post('/', [OpeningBalanceController::class, 'store'])
                ->middleware('permission:WRITE:TRANSACTIONS,UPDATE:WORKSPACE');
        });

        /*
        | Reading the books. Every one of these is a query over journal
        | entries — no report here has numbers of its own.
        */
        Route::prefix('ledger')->group(function () {
            Route::get('trial-balance', [LedgerController::class, 'trialBalance'])
                ->middleware('permission:READ,LEDGER');

            Route::get('summary', [LedgerController::class, 'summary'])
                ->middleware('permission:READ,LEDGER');

            Route::get('accounts/{account}', [LedgerController::class, 'account'])
                ->whereNumber('account')
                ->middleware('permission:READ,LEDGER');
        });

        /*
        | Reports and worklists — M12. Every one of these is a query over
        | journal entries, stock movements or bill lines; no report here has a
        | stored number anywhere.
        |
        | Notice what is *absent*. The trial balance is `ledger/trial-balance`,
        | the stock summary `stock/summary`, the party statement
        | `parties/{id}/ledger`, and the transaction list with its filters and
        | its drill-down `transactions`. Re-exposing any of them under /reports
        | would be a second URL for one answer, and the second one is always the
        | one that drifts.
        |
        | Read-only by nature, so READ is the only action. The day book, the P&L
        | and the GST summary are the workshop's whole financial position, so
        | they need LEDGER — the authority only an owner holds.
        */
        Route::prefix('reports')->group(function () {
            Route::get('meta', [ReportController::class, 'meta'])
                ->middleware('permission:READ,TRANSACTIONS');

            Route::get('day-book', [ReportController::class, 'dayBook'])
                ->middleware('permission:READ,LEDGER');

            Route::get('profit-and-loss', [ReportController::class, 'profitAndLoss'])
                ->middleware('permission:READ,LEDGER');

            Route::get('gst', [ReportController::class, 'gst'])
                ->middleware('permission:READ,LEDGER');

            // The one report a data-entry user holds, and deliberately: a parked
            // draft is *their own* unfinished work, not the workshop's financial
            // position, and a worklist only the owner could see would be a
            // worklist nobody acts on.
            Route::get('drafts', [ReportController::class, 'drafts'])
                ->middleware('permission:READ,TRANSACTIONS');
        });

        /*
        | The audit trail — M13. Who changed what, when.
        |
        | Two routes, and the shape is the point: there is no POST, no PATCH and
        | no DELETE anywhere in this group, and there cannot be. Entries arrive
        | through model events on the Auditable trait, and the model itself
        | refuses an UPDATE and a DELETE — so there is no verb here that could
        | put a claim on the trail, or take one off it. AUDIT accordingly has
        | only a READ grant, the same shape as LEDGER and STOCK.
        |
        | Note there is no per-record history endpoint. One record's own history
        | is `?resource=party&resource_id=12`, which is the same question at a
        | different filter — a second URL for one answer is a second thing to
        | keep in step, and the second one always drifts.
        |
        | Only OWNER holds this. A data-entry user does not, deliberately: the
        | trail records what they did, and reading it is not part of doing it.
        */
        Route::prefix('audit-logs')->group(function () {
            // Before any {id}-shaped route, the same habit that keeps
            // `accounts/types` working.
            Route::get('meta', [AuditLogController::class, 'meta'])
                ->middleware('permission:READ,AUDIT');

            Route::get('/', [AuditLogController::class, 'index'])
                ->middleware('permission:READ,AUDIT');
        });

        /*
        | Stored files — M14. Photographed invoices, recorded audio, PDFs.
        |
        | No PATCH and no PUT, and there never will be: a file's bytes do not
        | change, so correcting a bad photograph means taking another one. That
        | is why ATTACHMENTS has READ, WRITE and DELETE but no UPDATE — a grant
        | over an operation that does not exist would be a lie in the catalogue.
        |
        | DATA_ENTRY holds READ and WRITE, for the same reason it holds them on
        | parties and items: the person standing at the counter holding the paper
        | invoice is the person who photographs it. DELETE stays with the owner —
        | removing evidence is not data entry.
        */
        Route::prefix('attachments')->group(function () {
            // Before {attachment}, or "meta" is parsed as an id.
            Route::get('meta', [AttachmentController::class, 'meta'])
                ->middleware('permission:READ,ATTACHMENTS');

            Route::get('/', [AttachmentController::class, 'index'])
                ->middleware('permission:READ,ATTACHMENTS');

            Route::get('{attachment}', [AttachmentController::class, 'show'])
                ->whereNumber('attachment')
                ->middleware('permission:READ,ATTACHMENTS');

            // Named, because AttachmentResource builds this URL for every row.
            Route::get('{attachment}/download', [AttachmentController::class, 'download'])
                ->whereNumber('attachment')
                ->name('api.attachments.download')
                ->middleware('permission:READ,ATTACHMENTS');

            // Returns as soon as the bytes are stored and the row is written.
            // Reading the object back to confirm it landed is queued, and the
            // response carries the handle to watch — see AttachmentService.
            Route::post('/', [AttachmentController::class, 'store'])
                ->middleware('permission:WRITE,ATTACHMENTS');

            Route::delete('{attachment}', [AttachmentController::class, 'destroy'])
                ->whereNumber('attachment')
                ->middleware('permission:DELETE,ATTACHMENTS');
        });

        /*
        | Background work — M14. What has been queued, what is running, what
        | failed.
        |
        | Read-only, structurally: a run row is created by the act that
        | dispatched it — an upload, and in M15 a capture — never by a request to
        | this group. Hence a READ grant and nothing else, the same shape as
        | LEDGER and STOCK.
        |
        | DATA_ENTRY holds it: somebody who uploads a file has to be able to see
        | whether it went through, and a progress bar only the owner could watch
        | would be a progress bar nobody watches.
        |
        | `{job}` is a uuid rather than an id. It is what a client polls with,
        | and an incrementing integer in a URL invites a caller to try the one
        | next to it — the tenant scope would refuse them, but a system whose
        | safety rests on a check being present is weaker than one where guessing
        | is pointless.
        */
        Route::prefix('jobs')->group(function () {
            Route::get('/', [JobRunController::class, 'index'])
                ->middleware('permission:READ,JOBS');

            Route::get('{job}', [JobRunController::class, 'show'])
                ->whereUuid('job')
                ->middleware('permission:READ,JOBS');
        });

        /*
        | User management. Scoped to the caller's own tenant — see
        | EloquentUserRepository::scoped().
        */
        Route::prefix('users')->group(function () {
            Route::get('/', [UserController::class, 'index'])
                ->middleware('permission:READ,USERS');

            Route::get('{user}', [UserController::class, 'show'])
                ->whereNumber('user')
                ->middleware('permission:READ,USERS');

            Route::post('/', [UserController::class, 'store'])
                ->middleware('permission:WRITE,USERS');

            Route::patch('{user}', [UserController::class, 'update'])
                ->whereNumber('user')
                ->middleware('permission:UPDATE,USERS');

            Route::put('{user}/role', [UserController::class, 'assignRole'])
                ->whereNumber('user')
                ->middleware('permission:UPDATE:USERS,READ:ROLES');

            Route::put('{user}/status', [UserController::class, 'updateStatus'])
                ->whereNumber('user')
                ->middleware('permission:UPDATE,USERS');

            Route::delete('{user}', [UserController::class, 'destroy'])
                ->whereNumber('user')
                ->middleware('permission:DELETE,USERS');
        });
    });
});
