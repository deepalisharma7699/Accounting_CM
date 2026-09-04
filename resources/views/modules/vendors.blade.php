{{-- Everything below is `partials/counterparty-page`, which the Customers screen
     shares. Only the wording and the side of the position differ.

     `icon` and `tone` are the ones the module's card carries in
     config/modules.php, so the workspace looks like the card that opened it. --}}
@include('partials.counterparty-page', ['copy' => [
    'role' => \App\Enums\PartyRole::Vendor->value,
    'otherNoun' => 'customer',

    'noun' => 'vendor',
    'nounPlural' => 'vendors',

    'icon' => 'truck',
    'tone' => 'bg-amber-50 text-amber-600',

    'addLabel' => 'Add vendor',
    'formSubtitle' => 'Who the workshop buys from. Their bills, what is owed on them and every '
        .'payment made against them hang off this one record.',

    'searchLabel' => 'Search by name, phone, GSTIN…',
    'namePlaceholder' => 'e.g. National Copper Wire',

    'nameColumn' => 'Vendor Name',
    'outstandingColumn' => 'Outstanding Payable',
    'dateColumn' => 'Last Purchase',
    'dueLabel' => 'Payment Due',

    'historyTab' => 'Purchase History',
    'lifetimeLabel' => 'Total Purchased',
    'sinceLabel' => 'Vendor since',
    'createLabel' => 'Create Purchase Bill',
]])
