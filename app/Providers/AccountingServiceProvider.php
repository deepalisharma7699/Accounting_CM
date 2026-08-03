<?php

namespace App\Providers;

use App\Enums\TransactionType;
use App\Repositories\Contracts\ChartOfAccountRepositoryInterface;
use App\Repositories\Contracts\JournalEntryRepositoryInterface;
use App\Repositories\Contracts\PartyRepositoryInterface;
use App\Repositories\Contracts\TransactionLineRepositoryInterface;
use App\Repositories\Contracts\TransactionPaymentRepositoryInterface;
use App\Repositories\Contracts\TransactionRepositoryInterface;
use App\Repositories\Eloquent\EloquentChartOfAccountRepository;
use App\Repositories\Eloquent\EloquentJournalEntryRepository;
use App\Repositories\Eloquent\EloquentPartyRepository;
use App\Repositories\Eloquent\EloquentTransactionLineRepository;
use App\Repositories\Eloquent\EloquentTransactionPaymentRepository;
use App\Repositories\Eloquent\EloquentTransactionRepository;
use App\Services\Accounting\Posting\PostingTemplate;
use App\Services\Accounting\Posting\PostingTemplateRegistry;
use App\Services\Accounting\Posting\Templates\CustomerReceiptTemplate;
use App\Services\Accounting\Posting\Templates\ExpenseTemplate;
use App\Services\Accounting\Posting\Templates\ManualJournalTemplate;
use App\Services\Accounting\Posting\Templates\OpeningBalanceTemplate;
use App\Services\Accounting\Posting\Templates\PurchaseTemplate;
use App\Services\Accounting\Posting\Templates\SaleTemplate;
use App\Services\Accounting\Posting\Templates\StockAdjustmentTemplate;
use App\Services\Accounting\Posting\Templates\VendorPaymentTemplate;
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
}
