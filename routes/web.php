<?php

use App\Http\Controllers\DashboardController;
use Illuminate\Support\Facades\Route;

/*
| These are page shells only. Authentication is enforced client-side by
| resources/js/app.js, which redeems the HttpOnly refresh cookie on load and
| redirects to /login when there is no usable session. The data behind the
| pages is served by the JWT-guarded /api/v1 endpoints, so a shell reaching an
| unauthenticated visitor exposes nothing.
*/

/*
| The shopfront, and the way in.
|
| The public page is the site itself — what the workshop does, where it is and
| how to reach it — with the sign-in form on it as a modal rather than as a
| screen of its own. So there is no separate login page to route to.
|
| /login is kept, and kept named, because it is the destination the whole
| application already redirects to when a session ends: resources/js/app.js on a
| failed bootstrap, initLogout() after signing out, and route('login') from the
| sign-up page. It lands on the same page with the modal already open, so
| somebody who was working a moment ago is not made to hunt for the button.
*/
Route::view('/', 'welcome')->name('home');
Route::redirect('/login', '/?login=1')->name('login');

/*
| Sign-up. Gated server-side rather than merely hidden: with self-serve
| onboarding turned off, the page must not exist at all, or it would offer a
| form whose endpoint answers 403.
*/
Route::get('/register', function () {
    abort_unless((bool) config('tenancy.allow_public_signup', true), 404);

    return view('auth.register');
})->name('register');

Route::get('/dashboard', DashboardController::class)->name('dashboard');

// Accounting shells.
Route::view('/accounts', 'accounts.index')->name('accounts.index');
/*
| Counterparties, as two screens rather than one.
|
| They are two views of one `parties` table, not two tables: the shop that buys
| a rewound motor and sells you scrap copper is a single record holding both
| roles, with one combined ledger. Each list filters on role *membership*, so
| that record appears on both screens — deliberately, and labelled as such —
| rather than being forced into one of them or, worse, entered twice. Splitting
| a counterparty into two records is what splits a single balance in half, and
| nothing here does that.
*/
Route::view('/customers', 'customers.index')->name('customers.index');
Route::view('/vendors', 'vendors.index')->name('vendors.index');

// The screen these two replaced. Kept as a redirect rather than deleted, so a
// bookmark or a link from an older page still lands somewhere.
Route::redirect('/parties', '/customers')->name('parties.index');
Route::view('/items', 'items.index')->name('items.index');
Route::view('/stock', 'stock.index')->name('stock.index');
Route::view('/bills', 'bills.index')->name('bills.index');
Route::view('/journal', 'journal.index')->name('journal.index');
Route::view('/ledger', 'ledger.index')->name('ledger.index');
Route::view('/reports', 'reports.index')->name('reports.index');

// Go-live. Used once per workshop, which is why it sits under Settings in the
// nav rather than competing with the screens somebody opens every day.
Route::view('/opening', 'opening.index')->name('opening.index');

// The caller's own workshop: identity and the settings every report is built on.
Route::view('/workspace', 'workspace.index')->name('workspace.index');

// Stored files — M14. An upload returns as soon as the bytes are away; whether
// they can be read back is watched rather than waited on.
Route::view('/uploads', 'uploads.index')->name('uploads.index');

// The trail — M13. Who changed what, when: the master data underneath the
// figures, which is the part the ledger's own immutability does not cover.
Route::view('/audit', 'audit.index')->name('audit.index');

// Administration shells. The tables inside are filled from the guarded API, and
// every control is additionally gated on the user's grants client-side.
Route::view('/tenants', 'tenants.index')->name('tenants.index');
Route::view('/users', 'users.index')->name('users.index');
Route::view('/roles', 'roles.index')->name('roles.index');
