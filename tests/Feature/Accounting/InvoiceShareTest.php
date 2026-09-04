<?php

namespace Tests\Feature\Accounting;

use App\Enums\PartyRole;
use App\Models\InvoiceShare;
use App\Models\ItemVariant;
use App\Models\Party;
use App\Models\Tenant;
use App\Models\User;
use DOMDocument;
use DOMXPath;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Facades\Route;
use Illuminate\Testing\TestResponse;
use PHPUnit\Framework\Attributes\Test;
use Tests\Concerns\InteractsWithAuthModule;
use Tests\Concerns\InteractsWithLedger;
use Tests\Concerns\InteractsWithStock;
use Tests\Concerns\InteractsWithTenancy;
use Tests\TestCase;

/**
 * The customer's copy of an invoice, and the link that publishes it — M20.
 *
 * Two things are under test and they are not the same thing.
 *
 * **The document.** What a customer is shown is built from its own list of
 * fields rather than by filtering the internal one, so the assertions that
 * matter most here are absences: no cost, no margin, no "sold below cost", no
 * ledger entries. If any of those ever appears, the workshop's buying price has
 * been published to the person it buys against.
 *
 * **The link.** It has no expiry — a customer keeps an invoice — so revocation
 * is the whole of its lifetime, and the tests below are mostly about the ways it
 * has to stop working: revoked, reversed, never issued, or somebody else's.
 *
 * @see \App\Services\Accounting\InvoiceDocumentService
 * @see \App\Services\Accounting\InvoiceShareService
 */
class InvoiceShareTest extends TestCase
{
    use InteractsWithAuthModule;
    use InteractsWithLedger;
    use InteractsWithStock;
    use InteractsWithTenancy;
    use RefreshDatabase;

    private Tenant $tenant;

    private User $owner;

    protected function setUp(): void
    {
        parent::setUp();

        [$this->tenant, $this->owner] = $this->tenantWithUser([
            ['READ', 'TRANSACTIONS'], ['WRITE', 'TRANSACTIONS'], ['UPDATE', 'TRANSACTIONS'],
            ['READ', 'LEDGER'], ['READ', 'PARTIES'], ['READ', 'ITEMS'], ['READ', 'STOCK'],
        ]);

        $this->tenant->update(['gstin' => '27AAAAA0000A1Z5', 'state_code' => '27']);
        $this->tenant->refresh();
    }

    /* ---------------------------------------------------------------------
     | Harness
     |-------------------------------------------------------------------- */

    private function customer(): Party
    {
        return $this->actingForTenant($this->tenant, fn () => Party::factory()->create([
            'roles' => [PartyRole::Customer->value],
            'state_code' => '27',
            'gstin' => '27BBBBB0000B1Z5',
            'phone' => '9813707087',
        ]));
    }

    /** A stocked variant at a nominated rate, with plenty on the shelf. */
    private function variantAt(string $gstRate): ItemVariant
    {
        $variant = $this->variantFor($this->tenant, 'part');

        $this->actingForTenant($this->tenant, fn () => $variant->item->update(['gst_rate' => $gstRate]));

        $this->adjustStock($this->tenant, [[$variant, '1000', '100.00']]);

        return $variant;
    }

    /**
     * @param  array<int, array<string, mixed>>  $items
     * @param  array<string, mixed>  $extra
     */
    private function sale(array $items, ?Party $customer = null, array $extra = []): TestResponse
    {
        return $this->withHeaders($this->authHeader($this->owner))
            ->postJson('/api/v1/transactions/sale', array_merge([
                'date' => now()->toDateString(),
                'post' => true,
                'party_id' => ($customer ?? $this->customer())->id,
                'items' => $items,
            ], $extra));
    }

    /** One posted invoice, at 18%, and its id. */
    private function postedInvoice(?Party $customer = null): int
    {
        $variant = $this->variantAt('18.00');

        return (int) $this->sale([[
            'variant_id' => $variant->id,
            'quantity' => '2',
            'unit_price' => '1500.00',
        ]], $customer)->assertCreated()->json('data.id');
    }

    /**
     * @return array<string, mixed>
     */
    private function invoiceOf(int $id, ?User $as = null): array
    {
        return $this->withHeaders($this->authHeader($as ?? $this->owner))
            ->getJson("/api/v1/transactions/{$id}/invoice")
            ->assertOk()
            ->json();
    }

    private function shareLink(int $id): string
    {
        return (string) $this->withHeaders($this->authHeader($this->owner))
            ->postJson("/api/v1/transactions/{$id}/share")
            ->assertOk()
            ->json('data.url');
    }

    /** The path a browser would ask for, from the absolute URL that was issued. */
    private function pathOf(string $url): string
    {
        return (string) parse_url($url, PHP_URL_PATH);
    }

    /* ---------------------------------------------------------------------
     | The document
     |-------------------------------------------------------------------- */

    #[Test]
    public function the_invoice_carries_both_parties_the_lines_and_what_it_comes_to(): void
    {
        $payload = $this->invoiceOf($this->postedInvoice());
        $invoice = $payload['data'];

        $this->assertSame($this->tenant->name, $invoice['workshop']['name']);
        $this->assertSame('27AAAAA0000A1Z5', $invoice['workshop']['gstin']);

        $this->assertSame('27BBBBB0000B1Z5', $invoice['customer']['gstin']);
        $this->assertSame('9813707087', $invoice['customer']['phone']);

        $this->assertCount(1, $invoice['lines']);
        $this->assertSame('3000.00', $invoice['lines'][0]['taxable_value']);
        $this->assertSame('3000.00', $invoice['totals']['taxable']);

        // Same state both sides, so CGST + SGST rather than IGST — and the
        // document says which, because the columns depend on it.
        $this->assertFalse($invoice['totals']['inter_state']);
        $this->assertSame('270.00', $invoice['totals']['cgst']);
        $this->assertSame('270.00', $invoice['totals']['sgst']);
        $this->assertSame('3540.00', $invoice['totals']['total']);

        $this->assertSame('Three Thousand Five Hundred Forty Rupees Only', $invoice['totals']['in_words']);

        // Nothing has been paid, so the customer's copy says what is owed.
        $this->assertSame('3540.00', $invoice['settlement']['due']);
        $this->assertSame('unpaid', $invoice['settlement']['status']);
    }

    /**
     * The assertion the whole class exists for.
     *
     * `TransactionResource` carries the cost of every line, the margin on the
     * sale and whether it went out below cost. A customer must never see any of
     * it — the buying price is the workshop's negotiating position with its own
     * supplier, and with this customer next time.
     */
    #[Test]
    public function the_invoice_never_carries_what_anything_cost_the_workshop(): void
    {
        $id = $this->postedInvoice();

        // The internal document does carry them, which is what makes the
        // omission below meaningful rather than incidental.
        $internal = $this->withHeaders($this->authHeader($this->owner))
            ->getJson("/api/v1/transactions/{$id}")
            ->assertOk()
            ->json('data.items.0');

        $this->assertArrayHasKey('cost', $internal);
        $this->assertNotNull($internal['cost']);

        $encoded = json_encode($this->invoiceOf($id)['data']);

        foreach (['cost', 'margin', 'below_cost', 'movements', 'entries'] as $forbidden) {
            $this->assertStringNotContainsString(
                "\"{$forbidden}\"",
                (string) $encoded,
                "The customer's copy of an invoice must not carry `{$forbidden}`.",
            );
        }
    }

    #[Test]
    public function a_workshop_with_no_gstin_issues_a_bill_of_supply(): void
    {
        $this->assertSame('Tax Invoice', $this->invoiceOf($this->postedInvoice())['data']['document']['heading']);

        // Not a taxable supply, so not a tax invoice. Printing "Tax Invoice"
        // over it would be a claim the workshop is not entitled to make.
        $this->tenant->update(['gstin' => null]);

        $this->assertSame(
            'Bill of Supply',
            $this->invoiceOf($this->postedInvoice())['data']['document']['heading'],
        );
    }

    #[Test]
    public function a_credit_note_calls_itself_one(): void
    {
        $id = $this->postedInvoice();

        $creditNoteId = (int) $this->withHeaders($this->authHeader($this->owner))
            ->postJson("/api/v1/transactions/{$id}/return", [
                'lines' => [['line_no' => 1, 'quantity' => '1']],
            ])
            ->assertCreated()
            ->json('data.id');

        $invoice = $this->invoiceOf($creditNoteId)['data'];

        $this->assertSame('Credit Note', $invoice['document']['heading']);
        $this->assertSame($id, $invoice['document']['against_transaction_id']);

        // Null rather than zero: a credit note settles nothing on its own, and
        // "₹0 due" on one reads as a bill that has been paid.
        $this->assertNull($invoice['settlement']);
    }

    #[Test]
    public function a_discount_is_shown_as_a_subtotal_and_a_deduction(): void
    {
        $variant = $this->variantAt('18.00');

        $id = (int) $this->sale([[
            'variant_id' => $variant->id,
            'quantity' => '2',
            'unit_price' => '1500.00',
            'discount' => '200.00',
        ]])->assertCreated()->json('data.id');

        $totals = $this->invoiceOf($id)['data']['totals'];

        $this->assertSame('3000.00', $totals['gross']);
        $this->assertSame('200.00', $totals['discount']);
        $this->assertSame('2800.00', $totals['taxable']);
    }

    #[Test]
    public function the_rounding_appears_on_the_document_as_its_own_line(): void
    {
        $this->tenant->update(['round_off_invoices' => true]);

        $variant = $this->variantAt('18.00');

        // ₹5,678.90 at 18% is ₹6,701.10, charged as ₹6,701.
        $id = (int) $this->sale([[
            'variant_id' => $variant->id,
            'quantity' => '1',
            'unit_price' => '5678.90',
        ]])->assertCreated()->json('data.id');

        $totals = $this->invoiceOf($id)['data']['totals'];

        $this->assertSame('-0.10', $totals['round_off']);
        $this->assertSame('6701.00', $totals['total']);
        // The lines themselves did not move — that is the whole rule.
        $this->assertSame('5678.90', $totals['taxable']);
        $this->assertSame('Six Thousand Seven Hundred One Rupees Only', $totals['in_words']);
    }

    /* ---------------------------------------------------------------------
     | Publishing
     |-------------------------------------------------------------------- */

    #[Test]
    public function a_shared_invoice_opens_for_anybody_holding_the_link(): void
    {
        $id = $this->postedInvoice();

        $response = $this->get($this->pathOf($this->shareLink($id)));

        $response->assertOk();
        $response->assertSee($this->tenant->name, escape: false);
        // Kept out of search results twice — the header holds for a crawler that
        // only read the headers.
        $response->assertHeader('X-Robots-Tag', 'noindex, nofollow, noarchive');
        $response->assertSee('noindex', escape: false);
    }

    #[Test]
    public function the_public_page_never_carries_what_anything_cost_the_workshop(): void
    {
        $id = $this->postedInvoice();

        $html = $this->get($this->pathOf($this->shareLink($id)))->assertOk()->getContent();

        // The payload is embedded in the page, so this is the real check: the
        // buying price must not be in the bytes the customer downloads, whether
        // anything renders it or not.
        foreach (['below_cost', 'margin', '"cost"'] as $forbidden) {
            $this->assertStringNotContainsString($forbidden, (string) $html);
        }
    }

    /**
     * Print on the customer's page produces the invoice, not a blank sheet.
     *
     * The print rule in app.css keeps whichever child of `body` *contains*
     * `[data-invoice-document]` and hides every other one, which is how the
     * document ends up alone on the paper without a list of chrome to extend.
     * That rule used to name a host instead — `body > *:not(#invoice-print)` —
     * and this page has no element of that id: the `<main>` holding the document
     * was the one thing the rule hid, so Print here printed nothing at all.
     *
     * So what is asserted is the structural precondition the stylesheet is
     * written against, parsed rather than pattern-matched: exactly one child of
     * `body` holds the document. Two would print the invoice twice; none prints
     * a blank sheet, and neither failure shows on the screen.
     */
    #[Test]
    public function the_customers_page_holds_the_document_in_exactly_one_child_of_body(): void
    {
        $id = $this->postedInvoice();

        $html = (string) $this->get($this->pathOf($this->shareLink($id)))->assertOk()->getContent();

        $document = new DOMDocument;

        libxml_use_internal_errors(true);
        $document->loadHTML($html);
        libxml_clear_errors();

        $holders = (new DOMXPath($document))
            ->query('/html/body/*[descendant-or-self::*[@data-invoice-document]]');

        $this->assertSame(
            1,
            $holders->length,
            'Exactly one child of <body> must hold the invoice — see the print rule in app.css.',
        );

        // The page's own frame is screen furniture. Left on, it would centre a
        // 52rem sheet inside an A4 page and pad it again on top of the page
        // margin, and the customer's copy of an invoice would come out a
        // different shape from the workshop's copy of the same invoice — which
        // is the one difference between the two that nobody can settle.
        $frame = $holders->item(0)->getAttribute('class');

        foreach (['print:m-0', 'print:max-w-none', 'print:p-0'] as $reset) {
            $this->assertStringContainsString(
                $reset,
                $frame,
                'The customer copy must print to the same shape as the workshop copy.',
            );
        }
    }

    /**
     * The buttons are on the screen and not on the paper.
     *
     * They are inside the branch the print rule keeps, so they are the one piece
     * of chrome on this page that has to say so itself.
     */
    #[Test]
    public function the_customers_page_keeps_its_own_buttons_off_the_paper(): void
    {
        $id = $this->postedInvoice();

        $html = (string) $this->get($this->pathOf($this->shareLink($id)))->assertOk()->getContent();

        $document = new DOMDocument;

        libxml_use_internal_errors(true);
        $document->loadHTML($html);
        libxml_clear_errors();

        $printable = (new DOMXPath($document))->query(
            '//button[@data-invoice-print]/ancestor::*[contains(concat(" ", normalize-space(@class), " "), " print:hidden ")]'
        );

        $this->assertGreaterThan(
            0,
            $printable->length,
            'The action bar must be hidden on paper — it prints inside the branch the rule keeps.',
        );
    }

    #[Test]
    public function sharing_twice_hands_back_the_same_link(): void
    {
        $id = $this->postedInvoice();

        // A counter taps Share twice because the first tap did not look like it
        // did anything. A second token would leave the first live and
        // unrevocable from a screen that only ever shows one.
        $this->assertSame($this->shareLink($id), $this->shareLink($id));

        $this->assertSame(1, $this->actingForTenant(
            $this->tenant,
            fn () => InvoiceShare::query()->where('transaction_id', $id)->count(),
        ));
    }

    #[Test]
    public function revoking_stops_the_link_working_for_everybody(): void
    {
        $id = $this->postedInvoice();
        $path = $this->pathOf($this->shareLink($id));

        $this->get($path)->assertOk();

        $this->withHeaders($this->authHeader($this->owner))
            ->deleteJson("/api/v1/transactions/{$id}/share")
            ->assertOk();

        $this->get($path)->assertNotFound();
    }

    #[Test]
    public function sharing_again_after_a_revocation_mints_a_different_link(): void
    {
        $id = $this->postedInvoice();
        $first = $this->shareLink($id);

        $this->withHeaders($this->authHeader($this->owner))
            ->deleteJson("/api/v1/transactions/{$id}/share")
            ->assertOk();

        $second = $this->shareLink($id);

        $this->assertNotSame($first, $second);

        // The old one is over — permanently. Somebody who was told the link had
        // stopped working must not find it working again a week later.
        $this->get($this->pathOf($first))->assertNotFound();
        $this->get($this->pathOf($second))->assertOk();
    }

    #[Test]
    public function revoking_an_invoice_that_was_never_shared_is_not_an_error(): void
    {
        $id = $this->postedInvoice();

        // "Make sure this is not public" has been satisfied either way, and a
        // 404 here would send somebody looking for a link that never existed.
        $this->withHeaders($this->authHeader($this->owner))
            ->deleteJson("/api/v1/transactions/{$id}/share")
            ->assertOk()
            ->assertJsonPath('message', 'This invoice was not shared.');
    }

    #[Test]
    public function reversing_a_shared_invoice_stops_the_link_without_anything_revoking_it(): void
    {
        $id = $this->postedInvoice();
        $path = $this->pathOf($this->shareLink($id));

        $this->get($path)->assertOk();

        $this->withHeaders($this->authHeader($this->owner))
            ->postJson("/api/v1/transactions/{$id}/reverse", ['date' => now()->toDateString()])
            ->assertCreated();

        // The share row is untouched and still live. What stops the link is that
        // the document no longer stands — asked again on every read, so nothing
        // had to remember to revoke it.
        $this->assertTrue($this->actingForTenant(
            $this->tenant,
            fn () => InvoiceShare::query()->where('transaction_id', $id)->live()->exists(),
        ));

        $this->get($path)->assertNotFound();
    }

    #[Test]
    public function an_unknown_token_is_the_same_404_as_a_revoked_one(): void
    {
        $this->get('/i/'.str_repeat('a', 40))->assertNotFound();
    }

    /**
     * The one route anybody at all may reach, and the only one with no account
     * to rate-limit by.
     *
     * Asserted on the route rather than by making sixty-one requests: what could
     * regress is somebody moving the declaration and dropping the middleware,
     * and that is exactly what this catches — for the cost of no requests at all.
     */
    #[Test]
    public function the_public_route_is_throttled_and_the_limiter_exists(): void
    {
        $route = collect(Route::getRoutes()->getRoutes())
            ->first(fn ($candidate) => $candidate->getName() === 'invoices.public');

        $this->assertNotNull($route, 'The public invoice route must be declared.');
        $this->assertContains('throttle:public-invoice', $route->gatherMiddleware());

        // A `throttle:` middleware naming a limiter nobody registered is a 500 on
        // the first request, not a missing limit.
        $this->assertNotNull(RateLimiter::limiter('public-invoice'));
    }

    #[Test]
    public function one_workshops_token_opens_only_that_workshops_invoice(): void
    {
        $mine = $this->postedInvoice();
        $myPath = $this->pathOf($this->shareLink($mine));

        // A second workshop, with its own books and its own customer.
        [$other, $otherOwner] = $this->tenantWithUser([
            ['READ', 'TRANSACTIONS'], ['WRITE', 'TRANSACTIONS'], ['UPDATE', 'TRANSACTIONS'],
            ['READ', 'PARTIES'], ['READ', 'ITEMS'], ['READ', 'STOCK'],
        ]);

        $other->update(['name' => 'Somebody Elses Workshop']);

        $variant = $this->variantFor($other, 'part');
        $this->adjustStock($other, [[$variant, '100', '50.00']]);

        $theirCustomer = $this->actingForTenant($other, fn () => Party::factory()->create([
            'roles' => [PartyRole::Customer->value],
            'name' => 'Their Customer Only',
        ]));

        $theirs = (int) $this->withHeaders($this->authHeader($otherOwner))
            ->postJson('/api/v1/transactions/sale', [
                'date' => now()->toDateString(),
                'post' => true,
                'party_id' => $theirCustomer->id,
                'items' => [['variant_id' => $variant->id, 'quantity' => '1', 'unit_price' => '99.00']],
            ])
            ->assertCreated()
            ->json('data.id');

        $theirPath = $this->pathOf((string) $this->withHeaders($this->authHeader($otherOwner))
            ->postJson("/api/v1/transactions/{$theirs}/share")
            ->assertOk()
            ->json('data.url'));

        // Tenancy is established *from* the token, so each opens its own and
        // neither carries a trace of the other.
        $this->get($myPath)->assertOk()->assertDontSee('Their Customer Only', escape: false);
        $this->get($theirPath)->assertOk()->assertSee('Somebody Elses Workshop', escape: false);
    }

    /* ---------------------------------------------------------------------
     | What may not be published
     |-------------------------------------------------------------------- */

    #[Test]
    public function a_draft_cannot_be_shared(): void
    {
        $variant = $this->variantAt('18.00');

        $id = (int) $this->sale(
            [['variant_id' => $variant->id, 'quantity' => '1', 'unit_price' => '100.00']],
            null,
            ['post' => false],
        )->assertCreated()->json('data.id');

        $this->withHeaders($this->authHeader($this->owner))
            ->postJson("/api/v1/transactions/{$id}/share")
            ->assertStatus(422)
            ->assertJsonPath('error.code', 'INVOICE_NOT_POSTED');
    }

    #[Test]
    public function a_purchase_bill_cannot_be_shared(): void
    {
        $variant = $this->variantAt('18.00');

        $vendor = $this->actingForTenant($this->tenant, fn () => Party::factory()->create([
            'roles' => [PartyRole::Vendor->value],
        ]));

        $id = (int) $this->withHeaders($this->authHeader($this->owner))
            ->postJson('/api/v1/transactions/purchase', [
                'date' => now()->toDateString(),
                'post' => true,
                'party_id' => $vendor->id,
                'items' => [['variant_id' => $variant->id, 'quantity' => '1', 'unit_price' => '100.00']],
            ])
            ->assertCreated()
            ->json('data.id');

        // The vendor wrote it, under the vendor's own numbering. A workshop
        // publishing somebody else's invoice under its own letterhead is not a
        // feature.
        $this->withHeaders($this->authHeader($this->owner))
            ->postJson("/api/v1/transactions/{$id}/share")
            ->assertStatus(422)
            ->assertJsonPath('error.code', 'INVOICE_NOT_SHAREABLE');
    }

    #[Test]
    public function reading_the_document_needs_read_and_publishing_it_needs_write(): void
    {
        $id = $this->postedInvoice();

        $reader = $this->actingForTenant($this->tenant, fn () => User::factory()
            ->forTenant($this->tenant)
            ->withRole($this->roleWith([['READ', 'TRANSACTIONS']], 'Reader Only'))
            ->create());

        // Printing a document somebody may already look at asks nothing more of
        // a grant than looking at it does.
        $this->withHeaders($this->authHeader($reader))
            ->getJson("/api/v1/transactions/{$id}/invoice")
            ->assertOk();

        // Publishing it outside the workshop is a different act.
        $this->withHeaders($this->authHeader($reader))
            ->postJson("/api/v1/transactions/{$id}/share")
            ->assertForbidden();

        $this->withHeaders($this->authHeader($reader))
            ->deleteJson("/api/v1/transactions/{$id}/share")
            ->assertForbidden();
    }

    #[Test]
    public function a_credit_note_can_be_shared(): void
    {
        $id = $this->postedInvoice();

        $creditNoteId = (int) $this->withHeaders($this->authHeader($this->owner))
            ->postJson("/api/v1/transactions/{$id}/return", [
                'lines' => [['line_no' => 1, 'quantity' => '1']],
            ])
            ->assertCreated()
            ->json('data.id');

        // The customer needs their copy of what came back as much as their copy
        // of what went out.
        $this->get($this->pathOf($this->shareLink($creditNoteId)))
            ->assertOk()
            ->assertSee('Credit Note', escape: false);
    }
}
