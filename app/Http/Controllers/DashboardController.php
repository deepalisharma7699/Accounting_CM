<?php

namespace App\Http\Controllers;

use Illuminate\Contracts\View\View;

class DashboardController extends Controller
{
    /**
     * The dashboard shell.
     *
     * The authenticated user (greeting name, avatar initial, role) is hydrated
     * client-side from GET /api/v1/auth/me — see resources/js/app.js.
     *
     * The accounting figures below are PLACEHOLDER content matching the design.
     * There is no transactions/inventory backend yet; when those endpoints
     * exist, replace each array with a call to the relevant service.
     */
    public function __invoke(): View
    {
        return view('dashboard', [
            'heading' => 'Home',
            'greeting' => $this->greeting(),
            'workspace' => 'XYZ Workshop',

            'quickActions' => [
                ['title' => 'Record Transaction', 'description' => 'Log a sale, purchase, or service entry', 'icon' => 'file-plus',       'tone' => 'blue'],
                ['title' => 'Add Expense',        'description' => 'Record a workshop expense quickly',     'icon' => 'dollar-sign',     'tone' => 'green'],
                ['title' => 'Upload Invoice',     'description' => 'Scan or upload a bill or receipt',      'icon' => 'camera',          'tone' => 'purple'],
                ['title' => 'View Drafts',        'description' => 'Continue saved or pending entries',     'icon' => 'clipboard-list',  'tone' => 'amber'],
            ],

            'attentionPending' => 4,

            'attention' => [
                ['count' => 4, 'label' => 'Draft Transactions',  'description' => 'Incomplete entries waiting to be finished', 'action' => 'Review', 'icon' => 'clipboard-list', 'tone' => 'amber'],
                ['count' => 6, 'label' => 'Low Stock Items',     'description' => 'Components below reorder level',            'action' => 'View',   'icon' => 'package',        'tone' => 'rose'],
                ['count' => 3, 'label' => 'Vendor Payments Due', 'description' => 'Outstanding payables this week',            'action' => 'Review', 'icon' => 'credit-card',    'tone' => 'blue'],
                ['count' => 2, 'label' => 'OCR Processing Failed', 'description' => 'Invoices that could not be read',         'action' => 'Retry',  'icon' => 'refresh-cw',     'tone' => 'amber'],
            ],

            'drafts' => [
                ['title' => 'Purchase Draft',   'subtitle' => 'Saved 15 minutes ago', 'badge' => 'Purchase',     'icon' => 'shopping-cart', 'tone' => 'blue'],
                ['title' => 'Voice Transaction', 'subtitle' => 'Needs Confirmation',  'badge' => 'Needs Review', 'icon' => 'mic',           'tone' => 'amber'],
            ],

            'notifications' => [
                ['title' => 'Inventory import completed successfully', 'time' => '2m ago', 'icon' => 'check-circle',    'tone' => 'green'],
                ['title' => 'Daily backup completed',                  'time' => '1h ago', 'icon' => 'check-circle',    'tone' => 'green'],
                ['title' => 'Software update v2.4.1 available',        'time' => '3h ago', 'icon' => 'info',            'tone' => 'blue'],
                ['title' => 'Subscription renews in 7 days',           'time' => 'Today',  'icon' => 'alert-triangle',  'tone' => 'amber'],
            ],

            'activity' => [
                ['time' => '10:45 AM', 'title' => 'Sale Recorded',         'amount' => 14500, 'source' => 'AI',     'tone' => 'blue'],
                ['time' => '11:20 AM', 'title' => 'Purchase Added',        'amount' => 8200,  'source' => 'Manual', 'tone' => 'slate'],
                ['time' => '12:05 PM', 'title' => 'Expense Added',         'amount' => 500,   'source' => 'Manual', 'tone' => 'slate'],
                ['time' => '12:38 PM', 'title' => 'Invoice Uploaded',      'amount' => 23400, 'source' => 'Image',  'tone' => 'purple'],
                ['time' => '01:14 PM', 'title' => 'Sale Recorded',         'amount' => 6750,  'source' => 'Voice',  'tone' => 'green'],
                ['time' => '01:55 PM', 'title' => 'Vendor Payment Logged', 'amount' => 31000, 'source' => 'Manual', 'tone' => 'slate'],
                ['time' => '02:22 PM', 'title' => 'Purchase Added',        'amount' => 4100,  'source' => 'AI',     'tone' => 'blue'],
                ['time' => '03:07 PM', 'title' => 'Expense Added',         'amount' => 1200,  'source' => 'Manual', 'tone' => 'slate'],
            ],
        ]);
    }

    private function greeting(): string
    {
        return match (true) {
            now()->hour < 12 => 'Good Morning',
            now()->hour < 17 => 'Good Afternoon',
            default => 'Good Evening',
        };
    }
}
