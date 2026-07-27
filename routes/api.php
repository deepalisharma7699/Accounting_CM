<?php

use App\Http\Controllers\Api\V1\AuthController;
use App\Http\Controllers\Api\V1\PermissionController;
use App\Http\Controllers\Api\V1\RoleController;
use App\Http\Controllers\Api\V1\UserController;
use App\Http\Middleware\EnsurePermission;
use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| Authentication & User Management API (v1)
|--------------------------------------------------------------------------
|
| Guards, from outermost in:
|   throttle:*   — request rate limiting (see AuthModuleServiceProvider)
|   auth.jwt     — verifies the Bearer access token   (authGuard)
|   permission:* — checks the required grant(s)       (permissionGuard)
|
*/

Route::prefix('v1')->group(function () {

    /*
    | Public auth endpoints. `refresh` and `logout` are unauthenticated by
    | design: they are driven by the refresh cookie, which is exactly what a
    | client presents when its access token has already expired.
    */
    Route::prefix('auth')->group(function () {
        Route::post('register', [AuthController::class, 'register'])
            ->middleware('throttle:auth-register');

        Route::post('login', [AuthController::class, 'login'])
            ->middleware('throttle:auth-login');

        Route::post('refresh', [AuthController::class, 'refresh'])
            ->middleware('throttle:auth-refresh');

        Route::post('logout', [AuthController::class, 'logout']);

        Route::middleware('auth.jwt')->group(function () {
            Route::get('me', [AuthController::class, 'me']);
            Route::post('logout-all', [AuthController::class, 'logoutAll']);
        });
    });

    Route::middleware(['auth.jwt', 'throttle:api'])->group(function () {

        /*
        | Permission catalogue (read-only: permissions are defined by the
        | application and seeded, never created through the API).
        */
        Route::get('permissions', [PermissionController::class, 'index'])
            ->middleware(EnsurePermission::using('READ', 'PERMISSIONS'));

        /*
        | Custom role management.
        */
        Route::prefix('roles')->group(function () {
            Route::get('/', [RoleController::class, 'index'])
                ->middleware('permission:READ,ROLES');

            Route::get('{role}', [RoleController::class, 'show'])
                ->whereNumber('role')
                ->middleware('permission:READ,ROLES');

            Route::post('/', [RoleController::class, 'store'])
                ->middleware('permission:WRITE,ROLES');

            Route::patch('{role}', [RoleController::class, 'update'])
                ->whereNumber('role')
                ->middleware('permission:UPDATE,ROLES');

            Route::delete('{role}', [RoleController::class, 'destroy'])
                ->whereNumber('role')
                ->middleware('permission:DELETE,ROLES');

            // Changing a role's grants requires authority over both roles and
            // the permission catalogue — hence two required permissions.
            Route::put('{role}/permissions', [RoleController::class, 'syncPermissions'])
                ->whereNumber('role')
                ->middleware('permission:UPDATE:ROLES,READ:PERMISSIONS');
        });

        /*
        | User management.
        */
        Route::prefix('users')->group(function () {
            Route::get('/', [UserController::class, 'index'])
                ->middleware('permission:READ,USERS');

            Route::get('{user}', [UserController::class, 'show'])
                ->whereNumber('user')
                ->middleware('permission:READ,USERS');

            Route::post('/', [UserController::class, 'store'])
                ->middleware('permission:WRITE,USERS');

            Route::patch('{user}', [UserController::class, 'update'])
                ->whereNumber('user')
                ->middleware('permission:UPDATE,USERS');

            Route::put('{user}/role', [UserController::class, 'assignRole'])
                ->whereNumber('user')
                ->middleware('permission:UPDATE:USERS,READ:ROLES');

            Route::put('{user}/status', [UserController::class, 'updateStatus'])
                ->whereNumber('user')
                ->middleware('permission:UPDATE,USERS');

            Route::delete('{user}', [UserController::class, 'destroy'])
                ->whereNumber('user')
                ->middleware('permission:DELETE,USERS');
        });
    });
});
