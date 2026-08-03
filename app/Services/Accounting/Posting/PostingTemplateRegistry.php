<?php

namespace App\Services\Accounting\Posting;

use App\Enums\TransactionType;
use App\Exceptions\Accounting\InvalidJournalException;
use App\Providers\AccountingServiceProvider;
use Illuminate\Contracts\Container\Container;

/**
 * Which template posts which kind of transaction.
 *
 * One per type, and a type with no template cannot be posted at all — a
 * transaction whose accounting consequences are undefined must not reach the
 * ledger by falling through to some default. Registered in
 * {@see AccountingServiceProvider}.
 *
 * Templates are resolved from the container on use rather than instantiated
 * here, because later ones need the chart of accounts injected.
 */
class PostingTemplateRegistry
{
    /**
     * @var array<string, class-string<PostingTemplate>>
     */
    private array $templates = [];

    public function __construct(private readonly Container $container) {}

    /**
     * @param  class-string<PostingTemplate>  $template
     */
    public function register(TransactionType $type, string $template): void
    {
        $this->templates[$type->value] = $template;
    }

    public function has(TransactionType $type): bool
    {
        return isset($this->templates[$type->value]);
    }

    /**
     * @throws InvalidJournalException
     */
    public function for(TransactionType $type): PostingTemplate
    {
        if (! $this->has($type)) {
            throw InvalidJournalException::noTemplate($type->value);
        }

        return $this->container->make($this->templates[$type->value]);
    }

    /**
     * The types that can currently be posted. `journal` today; payments,
     * bills, expenses and opening balances as M6 to M11 land.
     *
     * @return array<int, TransactionType>
     */
    public function postableTypes(): array
    {
        return array_values(array_filter(
            TransactionType::cases(),
            fn (TransactionType $type) => $this->has($type),
        ));
    }
}
