<?php

namespace App\Http\Controllers;

use Illuminate\Contracts\View\View;

/**
 * The application's one page shell.
 *
 * ## What used to be here
 *
 * A greeting, and before that five hard-coded arrays: quick actions that went
 * nowhere, an attention list of invented counts, fictional drafts and eight rows
 * of activity with amounts like ₹14,500. The arrays went when the screen was
 * hydrated from a guarded `GET /api/v1/dashboard` instead; the greeting went
 * when home became the module grid and nothing else.
 *
 * **That endpoint is gone too.** Home is the card grid, and a card carries no
 * figure — so `DashboardService` was 448 lines nothing called, answering "how is
 * the business doing" a second time beside Insights (M23), which answers it over
 * a period the reader chooses. One question, one module: if figures are ever
 * wanted on this screen they come from `/insights/*`, not from a second service
 * that would drift from it. See docs/insights-module.md.
 *
 * Nothing is passed to the view now, and nothing needs to be. The grid is built
 * from `config/modules.php`, and which cards a session may see is decided
 * client-side from `/auth/me` — every card is additionally gated server-side on
 * the endpoints behind it.
 *
 * That matters beyond tidiness: this shell is public. Anything rendered here is
 * HTML anybody can fetch, which is precisely why a workshop's figures were never
 * allowed back into it.
 */
class DashboardController extends Controller
{
    public function __invoke(): View
    {
        return view('dashboard');
    }
}
