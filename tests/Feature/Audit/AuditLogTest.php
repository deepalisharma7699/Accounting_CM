<?php

namespace Tests\Feature\Audit;

use App\Enums\AuditAction;
use App\Enums\AuditResource;
use App\Enums\PartyRole;
use App\Enums\SystemAccount;
use App\Enums\TransactionType;
use App\Exceptions\Audit\AuditImmutableException;
use App\Models\AuditLog;
use App\Models\ChartOfAccount;
use App\Models\Item;
use App\Models\Party;
use App\Models\Tenant;
use App\Models\User;
use App\Services\Accounting\PostingEngine;
use App\Services\Audit\AuditRecorder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use Tests\Concerns\InteractsWithAuthModule;
use Tests\Concerns\InteractsWithLedger;
use Tests\Concerns\InteractsWithTenancy;
use Tests\TestCase;

/**
 * The trail itself — M13.
 *
 * Every test here makes history the way a user does: by editing a party, or
 * archiving an account. Nothing manufactures an {@see AuditLog} row, because
 * there is no factory to do it with — a row that no model event produced would
 * be a claim about something that never happened, and a test written against one
 * asserts against its own fixture rather than against the application.
 */
class AuditLogTest extends TestCase
{
    use InteractsWithAuthModule, InteractsWithLedger, InteractsWithTenancy, RefreshDatabase;

    private Tenant $tenant;

    private User $owner;

    protected function setUp(): void
    {
        parent::setUp();

        [$this->tenant, $this->owner] = $this->tenantWithUser([['*', '*']]);
    }

    /* ---------------------------------------------------------------------
     | What gets recorded
     |-------------------------------------------------------------------- */

    #[Test]
    public function editing_a_party_records_who_changed_which_fields(): void
    {
        $party = $this->asOwner(fn () => Party::factory()->create([
            'name' => 'Bharat Motors',
            'gstin' => '27AAAAA0000A1Z5',
            'phone' => null,
        ]));

        $this->asOwner(fn () => $party->update(['gstin' => '29AAAAA0000A1Z5', 'phone' => '9812345678']));

        $entry = $this->latestFor(AuditResource::Party, $party->id);

        $this->assertSame(AuditAction::Updated, $entry->action);
        $this->assertSame('Bharat Motors', $entry->label);
        $this->assertSame($this->owner->id, $entry->actor_id);
        $this->assertSame($this->owner->name, $entry->actor_name);

        // Only the fields that moved, with both sides. A GSTIN decides whether
        // an invoice is intra- or inter-state, so this is the single most
        // consequential silent change in the product.
        //
        // Asserted key by key rather than against a whole array: MySQL's JSON
        // type re-orders object keys, so a whole-array assertSame would be
        // testing the storage engine's key ordering rather than the trail.
        $this->assertSame('27AAAAA0000A1Z5', $entry->changed_fields['gstin']['from']);
        $this->assertSame('29AAAAA0000A1Z5', $entry->changed_fields['gstin']['to']);
        $this->assertNull($entry->changed_fields['phone']['from']);
        $this->assertSame('9812345678', $entry->changed_fields['phone']['to']);
        $this->assertArrayNotHasKey('name', $entry->changed_fields);
    }

    #[Test]
    public function a_save_that_changed_nothing_auditable_writes_no_entry(): void
    {
        $party = $this->asOwner(fn () => Party::factory()->create(['name' => 'Bharat Motors']));

        $before = $this->countFor(AuditResource::Party, $party->id);

        // Saving the same values, then touching a column nobody declared.
        $this->asOwner(function () use ($party) {
            $party->update(['name' => 'Bharat Motors']);
            $party->forceFill(['updated_at' => now()->addMinute()])->save();
        });

        // "Somebody pressed save" is not history, and a trail full of empty
        // edits is a trail nobody reads.
        $this->assertSame($before, $this->countFor(AuditResource::Party, $party->id));
    }

    #[Test]
    public function archiving_and_restoring_are_their_own_actions(): void
    {
        $account = $this->asOwner(fn () => ChartOfAccount::factory()->create([
            'code' => '5900',
            'name' => 'Rent',
            'is_active' => true,
        ]));

        $this->asOwner(fn () => $account->update(['is_active' => false]));
        $this->assertSame(AuditAction::Archived, $this->latestFor(AuditResource::Account, $account->id)->action);

        $this->asOwner(fn () => $account->update(['is_active' => true]));
        $this->assertSame(AuditAction::Restored, $this->latestFor(AuditResource::Account, $account->id)->action);
    }

    #[Test]
    public function a_deletion_keeps_a_snapshot_because_nothing_else_survives_it(): void
    {
        $party = $this->asOwner(fn () => Party::factory()->create([
            'name' => 'One-off Supplier',
            'roles' => [PartyRole::Vendor->value],
            'gstin' => '27BBBBB0000B1Z5',
        ]));

        $id = $party->id;

        $this->asOwner(fn () => $party->delete());

        $entry = $this->latestFor(AuditResource::Party, $id);

        $this->assertSame(AuditAction::Deleted, $entry->action);
        $this->assertSame('One-off Supplier', $entry->label);
        // Values as they stood. A creation gets no such snapshot — the record
        // itself is one — but a deletion leaves nothing behind at all.
        $this->assertSame('27BBBBB0000B1Z5', $entry->changed_fields['gstin']['from']);
        $this->assertNull($entry->changed_fields['gstin']['to']);
    }

    #[Test]
    public function creating_a_record_is_recorded_without_a_snapshot(): void
    {
        $item = $this->asOwner(fn () => Item::factory()->create(['name' => 'Copper wire']));

        $entry = $this->latestFor(AuditResource::Item, $item->id);

        $this->assertSame(AuditAction::Created, $entry->action);
        $this->assertSame('Copper wire', $entry->label);
        // No "before" exists, and the record is its own "after".
        $this->assertNull($entry->changed_fields);
        $this->assertFalse($entry->action->carriesChanges());
    }

    #[Test]
    public function changing_the_financial_year_is_recorded_against_the_workshop(): void
    {
        // The reason M13 exists. Nothing about this changes a posted figure, and
        // it silently re-cuts every period report ever run.
        $this->asOwner(fn () => $this->tenant->update([
            'financial_year_start_month' => 1,
            'books_start_date' => '2026-01-01',
        ]));

        $entry = $this->latestFor(AuditResource::Workspace, $this->tenant->id);

        $this->assertSame(AuditAction::Updated, $entry->action);
        $this->assertSame(4, $entry->changed_fields['financial_year_start_month']['from']);
        $this->assertSame(1, $entry->changed_fields['financial_year_start_month']['to']);
        // A workshop is not inside a workshop — the entry lands in its own
        // history, which is where somebody will look for it.
        $this->assertSame($this->tenant->id, $entry->tenant_id);
    }

    /* ---------------------------------------------------------------------
     | What is deliberately not recorded
     |-------------------------------------------------------------------- */

    #[Test]
    public function posting_a_transaction_writes_nothing_to_the_trail(): void
    {
        $before = $this->asOwner(fn () => AuditLog::query()->count());

        $this->asOwner(function () {
            app(PostingEngine::class)->postComposed(TransactionType::Journal, [
                'date' => now()->toDateString(),
                'notes' => 'Opening float',
                'lines' => [
                    ['account_id' => $this->accountId(SystemAccount::Cash), 'debit' => '1000.00'],
                    ['account_id' => $this->accountId(SystemAccount::OpeningBalanceEquity), 'credit' => '1000.00'],
                ],
            ], $this->owner);
        });

        // The point of M13, stated as a test. A posted transaction cannot be
        // edited or deleted, journal entries and stock movements refuse an
        // UPDATE on the model, and created_by and posted_at are already on the
        // transaction. An audit row here would be a second copy of a fact the
        // first copy already proves.
        $this->assertSame($before, $this->asOwner(fn () => AuditLog::query()->count()));
    }

    #[Test]
    public function a_users_password_never_reaches_the_trail(): void
    {
        $user = $this->asOwner(fn () => User::factory()->forTenant($this->tenant)->create([
            'name' => 'Ramesh',
        ]));

        $this->asOwner(fn () => $user->update([
            'password' => 'a-brand-new-secret-value',
            'name' => 'Ramesh Kumar',
        ]));

        $entry = $this->latestFor(AuditResource::User, $user->id);

        $this->assertSame('Ramesh', $entry->changed_fields['name']['from']);
        $this->assertSame('Ramesh Kumar', $entry->changed_fields['name']['to']);
        $this->assertArrayNotHasKey('password', $entry->changed_fields);

        // The allow-list is what makes that structural rather than lucky: the
        // trail is built from what the model declares, so a hash cannot arrive
        // here however the column is written.
        $this->assertNotContains('password', (new User)->auditAttributes());
        $this->assertNotContains('remember_token', (new User)->auditAttributes());
    }

    #[Test]
    public function provisioning_a_workshop_does_not_put_fifteen_accounts_on_the_trail(): void
    {
        [$tenant] = $this->tenantWithUser([['*', '*']], 'Provisioned Role');

        $accountEntries = $this->actingForTenant(
            $tenant,
            fn () => AuditLog::query()->forResource(AuditResource::Account)->count(),
        );

        // Fifteen seeded accounts are not fifteen decisions — they are one act,
        // and it is already on the trail as the workshop's own creation.
        $this->assertSame(0, $accountEntries);

        $this->assertSame(1, $this->actingForTenant(
            $tenant,
            fn () => AuditLog::query()->forResource(AuditResource::Workspace, $tenant->id)->count(),
        ));
    }

    /* ---------------------------------------------------------------------
     | The guarantees
     |-------------------------------------------------------------------- */

    #[Test]
    public function an_entry_cannot_be_edited_or_deleted(): void
    {
        $party = $this->asOwner(fn () => Party::factory()->create(['name' => 'Bharat Motors']));
        $entry = $this->latestFor(AuditResource::Party, $party->id);

        $this->assertThrows(
            fn () => $this->asOwner(fn () => $entry->update(['label' => 'Something else'])),
            AuditImmutableException::class,
        );

        $this->assertThrows(
            fn () => $this->asOwner(fn () => $entry->delete()),
            AuditImmutableException::class,
        );

        $this->assertSame('Bharat Motors', $this->asOwner(fn () => AuditLog::find($entry->id)->label));
    }

    #[Test]
    public function an_edit_rolled_back_leaves_no_trail_claiming_it_happened(): void
    {
        $party = $this->asOwner(fn () => Party::factory()->create(['name' => 'Bharat Motors']));
        $before = $this->countFor(AuditResource::Party, $party->id);

        $this->asOwner(function () use ($party) {
            try {
                \DB::transaction(function () use ($party) {
                    $party->update(['name' => 'Renamed In A Doomed Transaction']);

                    throw new \RuntimeException('rolled back');
                });
            } catch (\RuntimeException) {
                // Expected.
            }
        });

        // The entry is written inside whatever transaction the caller opened, so
        // it rolls back with the change it describes. A trail that survived a
        // rollback would assert a change that never happened.
        $this->assertSame($before, $this->countFor(AuditResource::Party, $party->id));
        $this->assertSame('Bharat Motors', $this->asOwner(fn () => Party::find($party->id)->name));
    }

    #[Test]
    public function one_workshops_trail_is_invisible_to_another(): void
    {
        $mine = $this->asOwner(fn () => Party::factory()->create(['name' => 'Mine']));

        [$other, $stranger] = $this->tenantWithUser([['*', '*']], 'Stranger Role');

        $this->actingForTenant($other, fn () => Party::factory()->create(['name' => 'Theirs']));

        $labels = $this->actingForTenant(
            $other,
            fn () => AuditLog::query()->forResource(AuditResource::Party)->pluck('label')->all(),
        );

        $this->assertContains('Theirs', $labels);
        $this->assertNotContains('Mine', $labels);
        $this->assertNotNull($stranger);
        $this->assertNotNull($mine);
    }

    #[Test]
    public function the_trail_survives_the_person_who_made_it(): void
    {
        $clerk = $this->asOwner(fn () => User::factory()->forTenant($this->tenant)->create(['name' => 'Priya']));

        $party = app(AuditRecorder::class)->actingAs(
            $clerk,
            fn () => $this->asOwner(fn () => Party::factory()->create(['name' => 'Added By Priya'])),
        );

        $this->asOwner(fn () => $clerk->forceDelete());

        $entry = $this->latestFor(AuditResource::Party, $party->id);

        // The foreign key nulls out; the copied name does not. A history that
        // empties itself when somebody leaves is not a history.
        $this->assertNull($entry->fresh()->actor_id);
        $this->assertSame('Priya', $entry->fresh()->actor_name);
        $this->assertSame('Priya', $entry->fresh()->actorLabel());
    }

    #[Test]
    public function a_suppressed_scope_records_nothing_and_restores_itself(): void
    {
        $recorder = app(AuditRecorder::class);
        $before = $this->asOwner(fn () => AuditLog::query()->count());

        $recorder->silently(function () use ($recorder) {
            $this->asOwner(fn () => Party::factory()->create(['name' => 'Invisible']));

            // Nested, to prove the inner scope does not switch recording back on
            // for the outer — the depth, not a flag.
            $recorder->silently(fn () => $this->asOwner(
                fn () => Party::factory()->create(['name' => 'Also Invisible']),
            ));

            $this->assertTrue($recorder->isSuppressed());
        });

        $this->assertFalse($recorder->isSuppressed());
        $this->assertSame($before, $this->asOwner(fn () => AuditLog::query()->count()));

        $this->asOwner(fn () => Party::factory()->create(['name' => 'Visible Again']));
        $this->assertSame($before + 1, $this->asOwner(fn () => AuditLog::query()->count()));
    }

    /* ---------------------------------------------------------------------
     | Helpers
     |-------------------------------------------------------------------- */

    /**
     * @template TReturn
     *
     * @param  \Closure(): TReturn  $callback
     * @return TReturn
     */
    private function asOwner(\Closure $callback): mixed
    {
        $this->actingAs($this->owner);

        return $this->actingForTenant($this->tenant, $callback);
    }

    private function latestFor(AuditResource $resource, int $id): AuditLog
    {
        return $this->actingForTenant(
            $this->tenant,
            fn () => AuditLog::query()->forResource($resource, $id)->newestFirst()->firstOrFail(),
        );
    }

    private function countFor(AuditResource $resource, int $id): int
    {
        return $this->actingForTenant(
            $this->tenant,
            fn () => AuditLog::query()->forResource($resource, $id)->count(),
        );
    }

    private function accountId(SystemAccount $key): int
    {
        return ChartOfAccount::where('system_key', $key->value)->value('id');
    }
}
