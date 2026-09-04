<?php

namespace App\Providers;

use App\Enums\TransactionType;
use App\Repositories\Contracts\ChartOfAccountRepositoryInterface;
use App\Repositories\Contracts\DocumentSequenceRepositoryInterface;
use App\Repositories\Contracts\InvoiceShareRepositoryInterface;
use App\Repositories\Contracts\JournalEntryRepositoryInterface;
use App\Repositories\Contracts\PartyRepositoryInterface;
use App\Repositories\Contracts\TransactionAllocationRepositoryInterface;
use App\Repositories\Contracts\TransactionLineRepositoryInterface;
use App\Repositories\Contracts\TransactionPaymentRepositoryInterface;
use App\Repositories\Contracts\TransactionRepositoryInterface;
use App\Repositories\Eloquent\EloquentChartOfAccountRepository;
use App\Repositories\Eloquent\EloquentDocumentSequenceRepository;
use App\Repositories\Eloquent\EloquentInvoiceShareRepository;
use App\Repositories\Eloquent\EloquentJournalEntryRepository;
use App\Repositories\Eloquent\EloquentPartyRepository;
use App\Repositories\Eloquent\EloquentTransactionAllocationRepository;
use App\Repositories\Eloquent\EloquentTransactionLineRepository;
use App\Repositories\Eloquent\EloquentTransactionPaymentRepository;
use App\Repositories\Eloquent\EloquentTransactionRepository;
use App\Services\Accounting\Posting\PostingTemplate;
use App\Services\Accounting\Posting\PostingTemplateRegistry;
use App\Services\Accounting\Posting\Templates\CustomerReceiptTemplate;
use App\Services\Accounting\Posting\Templates\ExpenseTemplate;
use App\Services\Accounting\Posting\Templates\ManualJournalTemplate;
use App\Services\Accounting\Posting\Templates\OpeningBalanceTemplate;
use App\Services\Accounting\Posting\Templates\PayrollTemplate;
use App\Services\Accounting\Posting\Templates\PurchaseReturnTemplate;
use App\Services\Accounting\Posting\Templates\PurchaseTemplate;
use App\Services\Accounting\Posting\Templates\SalesReturnTemplate;
use App\Services\Accounting\Posting\Templates\SaleTemplate;
use App\Services\Accounting\Posting\Templates\StaffAdvanceTemplate;
use App\Services\Accounting\Posting\Templates\StockAdjustmentTemplate;
use App\Services\Accounting\Posting\Templates\VendorPaymentTemplate;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\ServiceProvider;

/**
 * Wires the accounting core: the chart of accounts, the ledger and the posting
 * engine — and, as each later slice lands, the template that gives its
 * transaction type meaning.
 */
class AccountingServiceProvider extends ServiceProvider
{
    /**
     * @var array<class-string, class-string>
     */
    private const REPOSITORIES = [
        ChartOfAccountRepositoryInterface::class => EloquentChartOfAccountRepository::class,
        TransactionRepositoryInterface::class => EloquentTransactionRepository::class,
        JournalEntryRepositoryInterface::class => EloquentJournalEntryRepository::class,
        PartyRepositoryInterface::class => EloquentPartyRepository::class,
        TransactionPaymentRepositoryInterface::class => EloquentTransactionPaymentRepository::class,
        TransactionLineRepositoryInterface::class => EloquentTransactionLineRepository::class,
        // M16 — the number on the document, and which invoice a receipt paid.
        DocumentSequenceRepositoryInterface::class => EloquentDocumentSequenceRepository::class,
        TransactionAllocationRepositoryInterface::class => EloquentTransactionAllocationRepository::class,
        // M20 — the link a customer opens their invoice with.
        InvoiceShareRepositoryInterface::class => EloquentInvoiceShareRepository::class,
    ];

    /**
     * One posting template per transaction type. A type that is not listed here
     * cannot be posted at all — see {@see PostingTemplateRegistry}.
     *
     * @var array<string, class-string<PostingTemplate>>
     */
    private const TEMPLATES = [
        TransactionType::Journal->value => ManualJournalTemplate::class,
        // Templates D and E — the simplest real transactions there are: money
        // moving, with no GST and no stock.
        TransactionType::Payment->value => VendorPaymentTemplate::class,
        TransactionType::Receipt->value => CustomerReceiptTemplate::class,
        // Template G — the first type to write two kinds of record inside one
        // database transaction: journal entries and stock movements.
        TransactionType::StockAdjustment->value => StockAdjustmentTemplate::class,
        // Templates A, B and C — the big ones. Everything above converges here:
        // a document, its tax, what it took off the shelf and what that cost.
        TransactionType::Sale->value => SaleTemplate::class,
        TransactionType::Purchase->value => PurchaseTemplate::class,
        // Template F — the simplest thing in the product that is still a real
        // document, and built last because by then the engine has nothing left
        // to discover.
        TransactionType::Expense->value => ExpenseTemplate::class,
        // Template H — the go-live declaration. The only template whose other
        // side is always equity, because an opening balance is not a transaction
        // with anybody: it is the workshop stating what it was already worth.
        TransactionType::Opening->value => OpeningBalanceTemplate::class,
        // M18 — the credit and debit notes. Subclasses of the two above with
        // every side inverted, so the tax arithmetic that ends up on a
        // government return exists in exactly one place.
        TransactionType::SalesReturn->value => SalesReturnTemplate::class,
        TransactionType::PurchaseReturn->value => PurchaseReturnTemplate::class,
        /*
        | M22 — what the staff are paid, and what they are lent against it.
        |
        | Registered here rather than in StaffServiceProvider although the module
        | has one, and deliberately: this list is the single answer to "which
        | types can reach the ledger", and a second registry in another provider
        | would be a second place to look for a type that will not post.
        |
        | Template I is a settlement in the shape template D already had, so it
        | subclasses it. Template J is not — a payroll run is the month's wage
        | bill, and the split is only how the remainder was handed over after
        | advances were recovered against it.
        */
        TransactionType::StaffAdvance->value => StaffAdvanceTemplate::class,
        TransactionType::Payroll->value => PayrollTemplate::class,
    ];

    public function register(): void
    {
        foreach (self::REPOSITORIES as $contract => $implementation) {
            // Singleton, not bind: the chart repository memoises resolved
            // system accounts for the life of the request, and a fresh
            // instance per injection would throw that away exactly where it
            // matters most — inside the posting engine.
            $this->app->singleton($contract, $implementation);
        }

        $this->app->singleton(PostingTemplateRegistry::class, function ($app) {
            $registry = new PostingTemplateRegistry($app);

            foreach (self::TEMPLATES as $type => $template) {
                $registry->register(TransactionType::from($type), $template);
            }

            return $registry;
        });
    }

    public function boot(): void
    {
        /*
        | The one route in this application that anybody at all may reach — the
        | shared invoice at /i/{token}.
        |
        | Per IP, because there is no account to key on; that is the whole point
        | of the page. The limit is not really about guessing the token — forty
        | characters of Str::random is not brute-forceable at any rate a web
        | server could serve — it is about the cost of *serving* the guesses. An
        | unthrottled public route that runs a tenant lookup and builds a
        | document is a way to spend a workshop's database on nothing.
        |
        | Sixty a minute is generous for the real use: a customer opens the link,
        | reloads it once, and prints it. A workshop showing an invoice to a
        | queue of people on one shop wifi still fits.
        */
        RateLimiter::for(
            'public-invoice',
            fn (Request $request) => Limit::perMinute(60)->by((string) $request->ip()),
        );
    }
}
