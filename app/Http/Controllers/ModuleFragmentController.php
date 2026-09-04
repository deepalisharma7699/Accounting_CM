<?php

namespace App\Http\Controllers;

use App\Support\Modules;
use Illuminate\Contracts\View\View;

/**
 * One module's markup, without a page around it.
 *
 * The shell fetches this on the first open of a module and keeps the node from
 * then on, so a module's markup, its code and its data all arrive once — the
 * dashboard never ships a module it was not asked for, and reopening one costs
 * nothing (CLAUDE.md §2.5, §7.2).
 *
 * A fragment rather than a page: returning a full document would mean either a
 * navigation or a second `<html>` inside the mounted one, and the whole point of
 * the shell is that it never unmounts.
 *
 * Public, exactly as the page shells it replaces were. Every figure inside
 * arrives from the JWT-guarded /api/v1 endpoints, so a fragment reaching an
 * unauthenticated visitor exposes nothing — which is what the
 * `…_exposes_no_records_to_anonymous_visitors` tests have asserted all along.
 */
class ModuleFragmentController extends Controller
{
    public function __invoke(string $module): View
    {
        // The whitelist, not a string interpolated into a view name. `$module`
        // comes from the URL.
        abort_unless(Modules::exists($module), 404);

        return view("modules.{$module}");
    }
}
