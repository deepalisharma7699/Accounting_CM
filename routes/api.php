<?php

use App\Http\Controllers\Api\V1\AttachmentController;
use App\Http\Controllers\Api\V1\AttendanceController;
use App\Http\Controllers\Api\V1\AuditLogController;
use App\Http\Controllers\Api\V1\AuthController;
use App\Http\Controllers\Api\V1\ChartOfAccountController;
use App\Http\Controllers\Api\V1\EmployeeController;
use App\Http\Controllers\Api\V1\InsightController;
use App\Http\Controllers\Api\V1\ItemBrandController;
use App\Http\Controllers\Api\V1\ItemCategoryController;
use App\Http\Controllers\Api\V1\InvoiceController;
use App\Http\Controllers\Api\V1\ItemController;
use App\Http\Controllers\Api\V1\JobRunController;
use App\Http\Controllers\Api\V1\LedgerController;
use App\Http\Controllers\Api\V1\OpeningBalanceController;
use App\Http\Controllers\Api\V1\PartyController;
use App\Http\Controllers\Api\V1\PayrollController;
use App\Http\Controllers\Api\V1\PermissionController;
use App\Http\Controllers\Api\V1\ReportController;
use App\Http\Controllers\Api\V1\RoleController;
use App\Http\Controllers\Api\V1\StaffAdvanceController;
use App\Http\Controllers\Api\V1\StaffDesignationController;
use App\Http\Controllers\Api\V1\StockController;
use App\Http\Controllers\Api\V1\UnitController;
use App\Http\Controllers\Api\V1\TenantController;
use App\Http\Controllers\Api\V1\TransactionController;
use App\Http\Controllers\Api\V1\UserController;
use App\Http\Controllers\Api\V1\WorkshopJobController;
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
        | exists, how they are reached, and where they stand — and DATA_ENTRY
        | holds READ and WRITE so a walk-in customer can be captured at the
        | counter. LEDGER is authority over the books, which is why
        | `{id}/ledger` and `{id}/statement` are guarded by it and not by
        | PARTIES.
        |
        | The line is between the *position* and the *entries behind it*, not
        | between the name and the money. `show` and `index?with_position=1`
        | carry `outstanding` under READ:PARTIES, deliberately: the bill form's
        | party picker states what a customer already owes at the moment it can
        | still change the decision, and the person making it holds PARTIES and
        | TRANSACTIONS and no LEDGER. Requiring LEDGER for one number would mean
        | the only user who can extend credit is the one who cannot see how much
        | has been extended. See `PartyApiTest`.
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

            // The counter's statement — M16, and the brief's §14/§15. Bills with
            // their paid and due, and the receipts that settled them.
            //
            // Beside `ledger` rather than replacing it, and gated identically:
            // the two answer different questions from the same postings — a
            // running balance that reconciles to the control account, and a list
            // of which invoices are outstanding — and neither can be derived from
            // the other.
            Route::get('{party}/statement', [PartyController::class, 'statement'])
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
        | The catalogue's vocabulary — the Category Master and the Unit Master.
        |
        | These replaced the `ItemType` and `UnitOfMeasure` enums, and they are
        | the reason the create form is universal: an admin adds a category with
        | six fields here, and the form asks for those six fields the next time it
        | is opened. No migration, no new API, no new component.
        |
        | ## Why reading is WRITE-adjacent and writing is UPDATE
        |
        | Reading needs only READ:ITEMS, because *every* screen that lists or
        | creates a product needs the vocabulary to render at all — gating it
        | higher would break the catalogue for the clerk who uses it most.
        |
        | Writing needs UPDATE:ITEMS, which DATA_ENTRY deliberately does not hold.
        | The split is the point: a clerk should be able to add a bearing without
        | fetching the owner (WRITE:ITEMS), and should not be able to restructure
        | what every product in the shop is asked to record. Deleting needs
        | DELETE:ITEMS, the tidying-up authority, for the same reason it does on
        | items and parties.
        |
        | DELETE only ever reaches a definition nothing depends on. A category
        | with products under it, a field products have answered, and a unit
        | anything is counted in are all refused and archived instead — the same
        | rule as an account, a party and an item, for the same reason: the record
        | that is left behind would lose the thing that explains it.
        */
        Route::prefix('item-categories')->group(function () {
            Route::get('/', [ItemCategoryController::class, 'index'])
                ->middleware('permission:READ,ITEMS');

            // Declared before {category}, or "templates" is parsed as an id.
            Route::post('templates', [ItemCategoryController::class, 'applyTemplate'])
                ->middleware('permission:UPDATE,ITEMS');

            Route::get('{category}', [ItemCategoryController::class, 'show'])
                ->whereNumber('category')
                ->middleware('permission:READ,ITEMS');

            Route::post('/', [ItemCategoryController::class, 'store'])
                ->middleware('permission:UPDATE,ITEMS');

            Route::patch('{category}', [ItemCategoryController::class, 'update'])
                ->whereNumber('category')
                ->middleware('permission:UPDATE,ITEMS');

            Route::delete('{category}', [ItemCategoryController::class, 'destroy'])
                ->whereNumber('category')
                ->middleware('permission:DELETE,ITEMS');

            /*
            | The fields a category asks for. Nested because a field has no
            | meaning apart from its category — "flow rate" is uninterpretable
            | without knowing it belongs to Water Pump — and because the category
            | is what decides whether a key is already taken, by itself or by an
            | ancestor it inherits from.
            */
            Route::get('{category}/attributes', [ItemCategoryController::class, 'attributes'])
                ->whereNumber('category')
                ->middleware('permission:READ,ITEMS');

            Route::post('{category}/attributes', [ItemCategoryController::class, 'storeAttribute'])
                ->whereNumber('category')
                ->middleware('permission:UPDATE,ITEMS');

            // Before {attribute}, or "order" is parsed as an id.
            Route::put('{category}/attributes/order', [ItemCategoryController::class, 'reorderAttributes'])
                ->whereNumber('category')
                ->middleware('permission:UPDATE,ITEMS');

            Route::patch('{category}/attributes/{attribute}', [ItemCategoryController::class, 'updateAttribute'])
                ->whereNumber('category')->whereNumber('attribute')
                ->middleware('permission:UPDATE,ITEMS');

            Route::delete('{category}/attributes/{attribute}', [ItemCategoryController::class, 'destroyAttribute'])
                ->whereNumber('category')->whereNumber('attribute')
                ->middleware('permission:DELETE,ITEMS');
        });

        /*
        | The Brand Master — whose the shop's products are.
        |
        | Gated exactly like the Category Master, and for the same reasons:
        | reading needs only READ:ITEMS because every screen that creates a
        | product needs the dropdown to render at all, writing needs UPDATE:ITEMS
        | so a clerk can add a bearing without being able to restructure the
        | shop's vocabulary, and deleting needs DELETE:ITEMS.
        |
        | DELETE only ever reaches a brand no product carries. One that is in use
        | is refused and archived instead — the same rule as a category, an
        | account and a party, for the same reason: the products left behind would
        | lose the name that says whose they are.
        */
        Route::prefix('item-brands')->group(function () {
            Route::get('/', [ItemBrandController::class, 'index'])
                ->middleware('permission:READ,ITEMS');

            Route::get('{brand}', [ItemBrandController::class, 'show'])
                ->whereNumber('brand')
                ->middleware('permission:READ,ITEMS');

            Route::post('/', [ItemBrandController::class, 'store'])
                ->middleware('permission:UPDATE,ITEMS');

            Route::patch('{brand}', [ItemBrandController::class, 'update'])
                ->whereNumber('brand')
                ->middleware('permission:UPDATE,ITEMS');

            Route::delete('{brand}', [ItemBrandController::class, 'destroy'])
                ->whereNumber('brand')
                ->middleware('permission:DELETE,ITEMS');
        });

        /*
        | The Unit Master.
        |
        | A unit's *code* is never editable and no route accepts one: it is what
        | `items.base_uom`, `transaction_lines.unit` and `workshop_job_parts.unit`
        | all store, and posted documents store it as a copy of what was true when
        | they were issued. Renaming 'kg' to 'metre' would silently reinterpret
        | every quantity ever recorded.
        */
        Route::prefix('units')->group(function () {
            Route::get('/', [UnitController::class, 'index'])
                ->middleware('permission:READ,ITEMS');

            Route::get('{unit}', [UnitController::class, 'show'])
                ->whereNumber('unit')
                ->middleware('permission:READ,ITEMS');

            Route::post('/', [UnitController::class, 'store'])
                ->middleware('permission:UPDATE,ITEMS');

            Route::patch('{unit}', [UnitController::class, 'update'])
                ->whereNumber('unit')
                ->middleware('permission:UPDATE,ITEMS');

            Route::delete('{unit}', [UnitController::class, 'destroy'])
                ->whereNumber('unit')
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

            /*
            | What a bill will come to, before anybody commits to it — M17, and
            | the brief's §12 confirmation step.
            |
            | Declared before `{transaction}` so "preview" is not parsed as an id,
            | the same habit that keeps `accounts/types` working.
            |
            | A separate verb rather than a flag on `sale`, exactly as
            | `opening-balances/preview` is: committing to the ledger must never
            | be something that happened because a boolean was left out.
            |
            | WRITE:TRANSACTIONS although it writes nothing. "What would this bill
            | be worth" is a question only the person about to write the bill has
            | any use for, and READ would hand a pricing calculator to everybody
            | who may look at the day book.
            */
            Route::post('preview', [TransactionController::class, 'previewBill'])
                ->middleware('permission:WRITE,TRANSACTIONS');

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

            /*
            | Correcting who did the work on a sale — M22.
            |
            | The one route in this group that writes to a document already in the
            | books without producing a new one, and the reason it may is that
            | nothing it touches is a figure: no entry, no movement, no total, and
            | nothing on the customer's copy. Correcting it any other way would
            | mean reversing and reissuing an invoice the customer is holding —
            | which on a sale is refused outright once the weighted average has
            | moved, so a write-once label would simply stay wrong for ever.
            |
            | UPDATE:TRANSACTIONS, and pointedly **not** a STAFF grant. The person
            | who notices that the wrong fitter is on an invoice is the person who
            | raised it, and only OWNER holds STAFF because it guards what people
            | are paid. Requiring it here would either lock the counter out of
            | fixing its own typo or push a wage-reading grant onto everybody who
            | writes a bill. The line is between the name and the money, exactly
            | as it is for a party's outstanding.
            */
            Route::patch('{transaction}/staff', [TransactionController::class, 'updateStaff'])
                ->whereNumber('transaction')
                ->middleware('permission:UPDATE,TRANSACTIONS');

            /*
            | Correcting a posted bill — the Edit that a ledger can actually
            | offer. A purchase or an invoice; never a note, and never anything
            | that is not a bill at all.
            |
            | WRITE and UPDATE together, and deliberately both: it writes two new
            | transactions, which is WRITE, *and* it changes the standing of an
            | existing one, which is UPDATE. Somebody trusted to raise a bill but
            | not to alter one should not be able to reach this by the back door
            | of raising two.
            |
            | An invoice is corrected on stricter terms than a bill, and the
            | posting engine is where that is enforced: a sale whose cost basis
            | has moved since it was raised is refused rather than restated. See
            | `PostingEngine::assertRevisionKeepsTheCostItSoldAt`.
            */
            Route::post('{transaction}/revise', [TransactionController::class, 'revise'])
                ->whereNumber('transaction')
                ->middleware('permission:WRITE:TRANSACTIONS,UPDATE:TRANSACTIONS');

            /*
            | Taking part of a bill back — M18, and the brief's scenarios 5 and 6.
            |
            | Beside `reverse`, not instead of it, because the two are different
            | acts. A reversal says the document was a mistake and cancels it
            | whole; a return says three of the four bearings are still the
            | customer's. The invoice stays posted and can be returned against
            | again.
            |
            | WRITE, because it writes a new transaction — the credit note.
            */
            Route::post('{transaction}/return', [TransactionController::class, 'storeReturn'])
                ->whereNumber('transaction')
                ->middleware('permission:WRITE,TRANSACTIONS');

            // What is still returnable on each line, so a counter screen can
            // stop somebody entering 3 when only 2 may come back.
            Route::get('{transaction}/returnable', [TransactionController::class, 'returnable'])
                ->whereNumber('transaction')
                ->middleware('permission:READ,TRANSACTIONS');

            /*
            | Which bills a receipt settled — M16.
            |
            | UPDATE rather than WRITE, and that is the honest grant: nothing new
            | is posted and nothing already posted is touched. An allocation
            | writes no journal entry, moves no balance and changes no total — it
            | records which invoice the workshop considers the money to have
            | discharged, which is why it can be corrected outright where every
            | other property of a posted transaction can only be reversed.
            */
            Route::post('{transaction}/allocate', [TransactionController::class, 'allocate'])
                ->whereNumber('transaction')
                ->middleware('permission:UPDATE,TRANSACTIONS');

            // What this receipt could still be pointed at. READ, because it is a
            // question rather than a decision.
            Route::get('{transaction}/open-bills', [TransactionController::class, 'openBills'])
                ->whereNumber('transaction')
                ->middleware('permission:READ,TRANSACTIONS');

            /*
            | The customer's copy of the document, and the link that publishes it
            | — M20.
            |
            | Three routes rather than a flag on `show`, because they are three
            | different acts on three different grants' worth of trust. Reading
            | the printable document asks no more than reading the transaction
            | does. Publishing it and un-publishing it are decisions, and they
            | are the same decision in both directions — see InvoiceController
            | for why WRITE and not READ, and not UPDATE either.
            |
            | `share` is POST rather than PUT although it is idempotent: it mints
            | a credential the first time, and a verb that reads as "make sure
            | this exists" would invite a client to call it on every page load.
            */
            Route::get('{transaction}/invoice', [InvoiceController::class, 'show'])
                ->whereNumber('transaction')
                ->middleware('permission:READ,TRANSACTIONS');

            Route::post('{transaction}/share', [InvoiceController::class, 'share'])
                ->whereNumber('transaction')
                ->middleware('permission:WRITE,TRANSACTIONS');

            Route::delete('{transaction}/share', [InvoiceController::class, 'revoke'])
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
        | Insight — M23. What the numbers mean, as opposed to what they are.
        |
        | The Reports group above is *statements*: a day book, a P&L, a GST
        | summary. Each answers "what is the figure", for somebody who already
        | knows which figure they want. This group answers the question before
        | that one — "is anything wrong, and where should I look" — and none of
        | it re-exposes anything the group above already serves. The insights
        | module consumes both, which is why there is one card and not two.
        |
        | Every one of these is a query over transaction lines, stock movements,
        | journal entries or payroll lines. There is no rollup table behind any
        | of it and there must never be one: this is the module most likely to be
        | given a nightly cache for speed and the one where a stale figure would
        | do the most damage.
        |
        | ## Why one endpoint per tab
        |
        | The overview is one screen painted once, so it is one request — five
        | round trips to fill it would be five chances for half of it to arrive.
        | The panels are the opposite case: a workshop that only ever opens the
        | ageing must not pay for a dead-stock scan (§7.2). Each is fetched on
        | the first click of its tab and held from then on.
        |
        | ## READ:LEDGER, and READ:STAFF as well for one of them
        |
        | LEDGER is the whole financial position on one screen, the same
        | authority the P&L needs. `people` additionally requires STAFF, and that
        | is a privacy boundary rather than a formality: STAFF is the one grant
        | in this application withheld because of what it reveals about
        | individuals, not because of what it lets somebody do. Widening this
        | route to LEDGER alone would put every wage in the building in front of
        | anybody who can read the books.
        |
        | `meta` sits on TRANSACTIONS, matching `reports/meta`: it publishes the
        | period presets and nothing about the workshop's money.
        */
        Route::prefix('insights')->group(function () {
            Route::get('meta', [InsightController::class, 'meta'])
                ->middleware('permission:READ,TRANSACTIONS');

            Route::get('overview', [InsightController::class, 'overview'])
                ->middleware('permission:READ,LEDGER');

            Route::get('sales', [InsightController::class, 'sales'])
                ->middleware('permission:READ,LEDGER');

            Route::get('purchase', [InsightController::class, 'purchase'])
                ->middleware('permission:READ,LEDGER');

            Route::get('stock', [InsightController::class, 'stock'])
                ->middleware('permission:READ,LEDGER');

            Route::get('credit', [InsightController::class, 'credit'])
                ->middleware('permission:READ,LEDGER');

            // Both grants, all required — see the note above.
            Route::get('people', [InsightController::class, 'people'])
                ->middleware('permission:READ:LEDGER,READ:STAFF');
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
        | Workshop jobs — M19, and the brief's §16 to §18. The motor on the
        | bench, from booked in to billed.
        |
        | `workshop-jobs` rather than `jobs`, because `jobs` above is the
        | background queue and has been since M14. Renaming that one to make room
        | was the other option and it is the worse one: a client polling
        | `/jobs/{uuid}` for an upload is in the wild, and a URL that quietly
        | started meaning something else would answer 404 at best. The workshop
        | resource takes the qualifier for the same reason its enum and its
        | permission do — see App\Enums\WorkshopJobStatus.
        |
        | WORKSHOP_JOBS throughout, and DATA_ENTRY holds READ, WRITE and UPDATE:
        | booking a motor in, moving it along the bench and writing parts onto it
        | is what the person at the counter does all day. DELETE stays with the
        | owner, and grants less than it sounds like — a job with a bill against
        | it cannot be deleted by anybody.
        |
        | `bill` is the exception, and deliberately so. It needs
        | WRITE:TRANSACTIONS as well, because raising an invoice is capturing a
        | business event whichever screen it was reached from. A jobs grant that
        | quietly conferred the ability to post to the ledger would be a hole in
        | the permission model rather than a convenience.
        */
        Route::prefix('workshop-jobs')->group(function () {
            // Before {job}, or "meta" is parsed as an id — the same habit that
            // keeps `accounts/types` working.
            Route::get('meta', [WorkshopJobController::class, 'meta'])
                ->middleware('permission:READ,WORKSHOP_JOBS');

            Route::get('/', [WorkshopJobController::class, 'index'])
                ->middleware('permission:READ,WORKSHOP_JOBS');

            Route::get('{job}', [WorkshopJobController::class, 'show'])
                ->whereNumber('job')
                ->middleware('permission:READ,WORKSHOP_JOBS');

            // What the counter screen opens pre-filled. READ, because it is a
            // question rather than a decision — nothing is posted and nothing is
            // marked as billed.
            Route::get('{job}/bill-preview', [WorkshopJobController::class, 'billPreview'])
                ->whereNumber('job')
                ->middleware('permission:READ,WORKSHOP_JOBS');

            Route::post('/', [WorkshopJobController::class, 'store'])
                ->middleware('permission:WRITE,WORKSHOP_JOBS');

            Route::patch('{job}', [WorkshopJobController::class, 'update'])
                ->whereNumber('job')
                ->middleware('permission:UPDATE,WORKSHOP_JOBS');

            // A pipeline move is its own verb, so that saving a typo correction
            // can never deliver a motor that is still on the bench.
            Route::put('{job}/status', [WorkshopJobController::class, 'updateStatus'])
                ->whereNumber('job')
                ->middleware('permission:UPDATE,WORKSHOP_JOBS');

            // Parts — §17. WRITE, because a part is a new record; removing one is
            // UPDATE, because what it changes is the job.
            Route::post('{job}/parts', [WorkshopJobController::class, 'storePart'])
                ->whereNumber('job')
                ->middleware('permission:WRITE,WORKSHOP_JOBS');

            Route::delete('{job}/parts/{part}', [WorkshopJobController::class, 'destroyPart'])
                ->whereNumber('job')->whereNumber('part')
                ->middleware('permission:UPDATE,WORKSHOP_JOBS');

            /*
            | The estimate — §18. PUT rather than PATCH: it replaces the quotation
            | whole, because an estimate is one document and a partial update
            | would produce a figure nobody ever saw as a whole.
            |
            | Approving is a separate POST, because saying yes is an event that
            | happens days after the quotation and not a property of it.
            */
            Route::put('{job}/estimate', [WorkshopJobController::class, 'saveEstimate'])
                ->whereNumber('job')
                ->middleware('permission:UPDATE,WORKSHOP_JOBS');

            Route::post('{job}/estimate/approve', [WorkshopJobController::class, 'approveEstimate'])
                ->whereNumber('job')
                ->middleware('permission:UPDATE,WORKSHOP_JOBS');

            Route::post('{job}/estimate/apply', [WorkshopJobController::class, 'applyEstimate'])
                ->whereNumber('job')
                ->middleware('permission:WRITE,WORKSHOP_JOBS');

            // Raising the invoice. Two grants, and the second is the point — see
            // the note above this group.
            Route::post('{job}/bill', [WorkshopJobController::class, 'bill'])
                ->whereNumber('job')
                ->middleware('permission:UPDATE:WORKSHOP_JOBS,WRITE:TRANSACTIONS');

            Route::delete('{job}', [WorkshopJobController::class, 'destroy'])
                ->whereNumber('job')
                ->middleware('permission:DELETE,WORKSHOP_JOBS');
        });

        /*
        | The people who work for the workshop — M22: the staff list, the
        | designations they hold, the attendance sheet, the payroll runs and the
        | advances paid against a salary.
        |
        | ## One resource, and where the second grant starts
        |
        | Everything here is STAFF, held by OWNER and by nobody else. That is the
        | one grant in this application withheld for privacy rather than for
        | authority: what each person in a workshop earns is not something the
        | clerk at the counter needs in order to do their job, so DATA_ENTRY has
        | no route in here at all.
        |
        | The line inside the module falls where the **money** starts. Paying an
        | advance and posting a payroll run reach the ledger, so both
        | additionally require WRITE:TRANSACTIONS — the same boundary
        | `workshop-jobs` draws between recording a repair and billing it, and it
        | exists so a staff grant cannot quietly become the ability to move cash
        | out of the till.
        |
        | ## Route order
        |
        | The named sub-resources are declared before `{employee}`, which is
        | constrained to a number — so `staff/attendance` can never be parsed as
        | an employee id.
        |
        | ## What is absent
        |
        | **No draft payroll, and therefore no `PATCH payroll/{run}` and no
        | discard.** A parked sheet is figures derived from an attendance register
        | that keeps moving under it; somebody would open a fortnight-old one and
        | pay a month that three subsequent absences had already made wrong. A run
        | is computed on demand, posted, and corrected by reversing it — which
        | frees the month to be run again. See `docs/staff-module.md`.
        |
        | **No DELETE for an employee who has been paid.** Their payslips and
        | their attendance would lose the name that explains them, so they are
        | archived instead — `PATCH {"left_on": "..."}` — the same rule as a
        | party or an account.
        */
        Route::prefix('staff')->group(function () {

            // Declared before {employee}: the two salary bases, the six
            // attendance states, the payment modes, and this workshop's own
            // designations — everything a client needs so that none of it is
            // written into markup.
            Route::get('meta', [EmployeeController::class, 'meta'])
                ->middleware('permission:READ,STAFF');

            /*
            | The Designation Master. The staff module's counterpart to the
            | catalogue's brand and unit masters, and gated with the rest of the
            | module rather than separately: whoever keeps the staff list is
            | whoever decides what the trades are called.
            */
            Route::get('designations', [StaffDesignationController::class, 'index'])
                ->middleware('permission:READ,STAFF');

            Route::post('designations', [StaffDesignationController::class, 'store'])
                ->middleware('permission:WRITE,STAFF');

            Route::patch('designations/{designation}', [StaffDesignationController::class, 'update'])
                ->whereNumber('designation')
                ->middleware('permission:UPDATE,STAFF');

            Route::delete('designations/{designation}', [StaffDesignationController::class, 'destroy'])
                ->whereNumber('designation')
                ->middleware('permission:DELETE,STAFF');

            /*
            | Attendance. One endpoint at two zoom levels — `?date=` is the day
            | sheet, `?period=` is the month register.
            |
            | The write is a PUT and takes the whole day at once, because that is
            | how the sheet is filled in: somebody opens the day, runs down the
            | list and saves. Sending the same sheet twice leaves the same
            | result, which is the property somebody tapping Save on a patchy
            | connection needs.
            |
            | UPDATE rather than WRITE, because marking a day is almost always
            | correcting one: the row very often already exists.
            */
            Route::get('attendance', [AttendanceController::class, 'index'])
                ->middleware('permission:READ,STAFF');

            Route::put('attendance', [AttendanceController::class, 'store'])
                ->middleware('permission:UPDATE,STAFF');

            /*
            | Payroll. `preview` writes nothing and reserves nothing; `store`
            | recomputes the same sheet and posts it.
            |
            | `preview` is a POST for a read, because the recovery overrides are a
            | map that does not belong in a query string. It is gated on READ
            | accordingly — it is a question, not a change.
            */
            Route::get('payroll', [PayrollController::class, 'index'])
                ->middleware('permission:READ,STAFF');

            Route::post('payroll/preview', [PayrollController::class, 'preview'])
                ->middleware('permission:READ,STAFF');

            Route::get('payroll/{run}', [PayrollController::class, 'show'])
                ->whereNumber('run')
                ->middleware('permission:READ,STAFF');

            // Two grants, and the second is the point — this posts to the ledger.
            Route::post('payroll', [PayrollController::class, 'store'])
                ->middleware('permission:WRITE:STAFF,WRITE:TRANSACTIONS');

            /*
            | Reversing a run. WRITE:TRANSACTIONS rather than UPDATE, matching
            | the transactions module's own rule: a reversal *writes* a new
            | transaction, and the original is untouched apart from its status.
            */
            Route::post('payroll/{run}/reverse', [PayrollController::class, 'reverse'])
                ->whereNumber('run')
                ->middleware('permission:UPDATE:STAFF,WRITE:TRANSACTIONS');

            /*
            | Advances. Posted outright — there is no draft, because an advance is
            | cash in somebody's hand at the moment they ask for it.
            */
            Route::get('advances', [StaffAdvanceController::class, 'index'])
                ->middleware('permission:READ,STAFF');

            Route::post('advances', [StaffAdvanceController::class, 'store'])
                ->middleware('permission:WRITE:STAFF,WRITE:TRANSACTIONS');

            Route::post('advances/{advance}/reverse', [StaffAdvanceController::class, 'reverse'])
                ->whereNumber('advance')
                ->middleware('permission:UPDATE:STAFF,WRITE:TRANSACTIONS');

            /* The staff list itself, last — {employee} is numeric-constrained. */
            Route::get('/', [EmployeeController::class, 'index'])
                ->middleware('permission:READ,STAFF');

            Route::get('{employee}', [EmployeeController::class, 'show'])
                ->whereNumber('employee')
                ->middleware('permission:READ,STAFF');

            /*
            | How much work one person has got through — M22.
            |
            | READ:STAFF, like the rest of the module. That is the right gate even
            | though every figure in it comes off invoices the counter can already
            | see: "which of my people is doing the work" is a question about
            | staff, and its answer sits next to their wages on the same screen.
            |
            | Its own route rather than another block on `show`, because it takes
            | a period and paginates — folding it in would make every drawer read
            | pay for a page of invoices nobody had asked for.
            */
            Route::get('{employee}/work', [EmployeeController::class, 'work'])
                ->whereNumber('employee')
                ->middleware('permission:READ,STAFF');

            Route::post('/', [EmployeeController::class, 'store'])
                ->middleware('permission:WRITE,STAFF');

            // Also the archive control: {"left_on": "2026-09-12"}.
            Route::patch('{employee}', [EmployeeController::class, 'update'])
                ->whereNumber('employee')
                ->middleware('permission:UPDATE,STAFF');

            Route::delete('{employee}', [EmployeeController::class, 'destroy'])
                ->whereNumber('employee')
                ->middleware('permission:DELETE,STAFF');
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
