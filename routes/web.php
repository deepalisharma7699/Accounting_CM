<?php

use App\Http\Controllers\DashboardController;
use App\Http\Controllers\ModuleFragmentController;
use App\Http\Controllers\PublicInvoiceController;
use App\Support\Modules;
use Illuminate\Support\Facades\Route;

/*
| These are page shells only. Authentication is enforced client-side by
| resources/js/app.js, which redeems the HttpOnly refresh cookie on load and
| redirects to /login when there is no usable session. The data behind the
| pages is served by the JWT-guarded /api/v1 endpoints, so a shell reaching an
| unauthenticated visitor exposes nothing.
|
| There is one page shell for the whole application — `/dashboard`. Modules open
| inside it (CLAUDE.md §1.3–§1.5), so there is no route per module any more;
| what is left below is the shopfront, the way in, the shell, the counter, and
| redirects that keep every old bookmark working.
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

/*
| One module's markup, without a page around it.
|
| The shell fetches this the first time a card is opened and keeps the node from
| then on. `{module}` is checked against the registry in config/modules.php
| rather than interpolated into a view name — see ModuleFragmentController.
*/
Route::get('/modules/{module}', ModuleFragmentController::class)
    ->where('module', '[a-z-]+')
    ->name('modules.show');

/*
| One invoice, opened by the customer it was written for — M20.
|
| The only route in the application that serves a workshop's own records to
| somebody with no account, which is why it is written out here rather than
| tucked in with the redirects. Three things hold it shut:
|
|   the token   — forty characters of Str::random, unique across every workshop,
|                 and the only thing on the request that says which workshop this
|                 is. See PublicInvoiceController for how tenancy is established
|                 *from* it rather than assumed alongside it.
|   revocation  — the link works until somebody ends it, and a revoked token is
|                 as dead as one that never existed. Both answer 404.
|   the throttle— per IP, because there is no account to key on.
|
| Short path on purpose: `/i/` rather than `/invoices/`, because this URL is
| pasted into WhatsApp and read off a phone screen by somebody standing at a
| counter. `{token}` is constrained to the alphabet Str::random draws from, so a
| malformed link never reaches the controller at all.
*/
Route::get('/i/{token}', PublicInvoiceController::class)
    ->where('token', '[A-Za-z0-9]{16,64}')
    ->middleware('throttle:public-invoice')
    ->name('invoices.public');

/*
| The counter — M20, decision D8. Still a page of its own this pass: a modal
| cannot host a search-first item picker, a running total, a keyboard flow and a
| confirmation step without becoming a scroll trap. It becomes the Bills module's
| level-1 create form in the follow-up, and this route goes with it.
*/
Route::view('/bills/new', 'bills.new')->name('bills.create');

/*
| Where every module used to live.
|
| Each one is now a card on the dashboard, opened in the mounted shell — so these
| are redirects rather than screens (CLAUDE.md §1.5). They keep their route names
| because the names are what the rest of the application links by, and they keep
| working as deep links: /dashboard reads the fragment on boot and opens that
| module without a second load.
|
| `/parties` is the screen Customers and Vendors replaced, and it redirects one
| step further along the same chain rather than being deleted.
|
| Declared for every module the registry names, including the ones currently
| switched off — `route('bills.index')` is still written in the counter's markup,
| and a missing route is a 500 where a redirect to the dashboard is a shrug.
*/
foreach (array_keys(Modules::declared()) as $module) {
    Route::redirect('/'.$module, '/dashboard#'.$module)->name($module.'.index');
}

Route::redirect('/parties', '/dashboard#customers')->name('parties.index');
