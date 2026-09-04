{{-- Everything below is `partials/counterparty-page`, which the Vendors screen
     shares. Only the wording and the side of the position differ.

     `icon` and `tone` are the ones the module's card carries in
     config/modules.php, so the workspace looks like the card that opened it. --}}
@include('partials.counterparty-page', ['copy' => [
    'role' => \App\Enums\PartyRole::Customer->value,
    'otherNoun' => 'vendor',

    'noun' => 'customer',
    'nounPlural' => 'customers',

    'icon' => 'users',
    'tone' => 'bg-emerald-50 text-emerald-600',

    'addLabel' => 'Add customer',
    'formSubtitle' => 'Who the workshop bills. Their invoices, what is outstanding on them and every '
        .'receipt against them hang off this one record.',

    'searchLabel' => 'Search by name, phone, GSTIN…',
    'namePlaceholder' => 'e.g. Ravi Kumar Motors',

    'nameColumn' => 'Customer Name',
    'outstandingColumn' => 'Outstanding',
    'dateColumn' => 'Last Sale',
    'dueLabel' => 'With Outstanding',

    'historyTab' => 'Sales History',
    'lifetimeLabel' => 'Total Billed',
    'sinceLabel' => 'Customer since',
    'createLabel' => 'Create Sale',
]])
