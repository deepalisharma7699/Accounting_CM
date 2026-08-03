@extends('layouts.app')

@section('title', 'Vendors')
@section('page', 'vendors')

@section('content')

{{-- Everything below is `partials/counterparty-page`, which the Customers screen
     shares. Only the wording and the side of the position differ. --}}
@include('partials.counterparty-page', ['copy' => [
    'role' => \App\Enums\PartyRole::Vendor->value,
    'otherNoun' => 'customer',

    'noun' => 'vendor',
    'nounPlural' => 'vendors',
    'icon' => 'building-2',

    'title' => 'Vendors',
    'subtitle' => 'Manage suppliers, purchase history, and outstanding vendor payments.',

    'addLabel' => 'Add Vendor',
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

@endsection
