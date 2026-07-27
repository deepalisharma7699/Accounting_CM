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

Route::view('/login', 'auth.login')->name('login');

Route::get('/dashboard', DashboardController::class)->name('dashboard');

// Administration shells. The tables inside are filled from the guarded API, and
// every control is additionally gated on the user's grants client-side.
Route::view('/users', 'users.index')->name('users.index');
Route::view('/roles', 'roles.index')->name('roles.index');

Route::redirect('/', '/dashboard');
