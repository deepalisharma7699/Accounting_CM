@extends('layouts.app')

@section('title', 'Customers')
@section('page', 'customers')

@section('content')

{{-- Everything below is `partials/counterparty-page`, which the Vendors screen
     shares. Only the wording and the side of the position differ. --}}
@include('partials.counterparty-page', ['copy' => [
    'role' => \App\Enums\PartyRole::Customer->value,
    'otherNoun' => 'vendor',

    'noun' => 'customer',
    'nounPlural' => 'customers',
    'icon' => 'users',

    'title' => 'Customers',
    'subtitle' => 'Manage customer information, outstanding balances, and sales history.',

    'addLabel' => 'Add Customer',
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

@endsection
