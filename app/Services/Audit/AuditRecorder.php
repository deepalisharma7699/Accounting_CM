<?php

namespace App\Services\Audit;

use App\Enums\AuditAction;
use App\Enums\AuditResource;
use App\Models\AuditLog;
use App\Models\Concerns\Auditable;
use App\Models\User;
use App\Support\Tenancy\TenantContext;
use BackedEnum;
use Closure;
use DateTimeInterface;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Auth;

/**
 * The one thing that writes to `audit_logs` — M13.
 *
 * Driven by model events from {@see Auditable} rather than called from
 * services, so the trail follows the write wherever the write comes from: the
 * API, M11's CSV importer, a console command, a queued job. Nothing has to
 * remember to call it, which is the only arrangement under which a trail can be
 * relied on.
 *
 * ## Why a failure here is allowed to fail the write
 *
 * It is not caught, not logged-and-swallowed, and not pushed onto a queue. If
 * the audit row cannot be written, the change it describes does not happen
 * either — both are inside the same database transaction, and both roll back
 * together.
 *
 * The instinct is the other way round: a broken trail should not stop a workshop
 * editing a supplier's phone number. But a trail with silent holes in it is
 * worse than no trail, because it is *believed*. The whole value of this table
 * is that a gap in it means nothing happened, and the moment a failure can leave
 * a change unrecorded, every absence becomes ambiguous — and the ambiguity
 * surfaces during the one conversation where somebody needs an answer. A loud
 * failure is a bug that gets fixed on the day it appears.
 *
 * ## Who the actor is
 *
 * Read from the authenticated user the JWT guard already bound to the request.
 * A model event cannot be handed an argument, which is exactly why every service
 * in this application takes `?User $actor` explicitly and this one does not —
 * see {@see actingAs()} for how a queued job or a console command supplies one,
 * since neither has an authenticated session to read.
 */
class AuditRecorder
{
    public function __construct(private readonly TenantContext $tenancy) {}

    /**
     * Depth rather than a flag, so nested {@see silently()} calls do not let the
     * inner one switch recording back on for the outer — the same reasoning
     * {@see \App\Support\Tenancy\TenantContext} uses for its unscoped depth.
     */
    private int $suppressed = 0;

    /**
     * The actor to attribute to when there is no authenticated request — set by
     * {@see actingAs()}, and null everywhere else.
     */
    private ?User $actor = null;

    /* ---------------------------------------------------------------------
     | Writing
     |-------------------------------------------------------------------- */

    /**
     * Record a creation or a deletion.
     *
     * Returns null rather than throwing when there is nothing to record — a
     * model with no tenant behind it, or a suppressed scope. Both are ordinary
     * and expected; see {@see resourceFor()} and {@see silently()}.
     */
    public function record(Model $model, AuditAction $action, ?array $changes = null): ?AuditLog
    {
        if ($this->suppressed > 0) {
            return null;
        }

        $resource = $this->resourceFor($model);
        $tenantId = $this->tenantFor($model);

        if ($resource === null || $tenantId === null) {
            return null;
        }

        if ($changes === null && $action === AuditAction::Deleted) {
            // Nothing survives a deletion, so the values are copied onto the row
            // as they stood. A creation gets no such snapshot, because the record
            // itself is one — see AuditAction::carriesChanges().
            $changes = $this->snapshot($model);
        }

        return $this->write($tenantId, $resource, $model, $action, $changes);
    }

    /**
     * Record an edit, working out which of the declared fields actually moved.
     *
     * Called from the `updated` model event, where Eloquent still holds both the
     * pre-save originals and the post-save values. A save that changed nothing
     * the model declares auditable writes no row at all: "somebody pressed save"
     * is not history, and a trail full of empty edits is a trail nobody reads.
     */
    public function recordUpdate(Model $model): ?AuditLog
    {
        if ($this->suppressed > 0) {
            return null;
        }

        $changes = $this->diff($model);

        if ($changes === []) {
            return null;
        }

        return $this->record($model, $this->actionForUpdate($model, $changes), $changes);
    }

    /**
     * An edit that flipped `is_active` is filed as an archive or a restore.
     *
     * Archiving is the closest thing this product has to a deletion — an account
     * or a party that has been transacted with is switched off rather than
     * removed, because its entries would otherwise lose the name that explains
     * them. "Who took our biggest supplier off the list" is a question people
     * actually ask, and filed under `updated` it would be one row among forty
     * field edits.
     *
     * @param  array<string, array{from: mixed, to: mixed}>  $changes
     */
    private function actionForUpdate(Model $model, array $changes): AuditAction
    {
        if (! array_key_exists('is_active', $changes)) {
            return AuditAction::Updated;
        }

        return $changes['is_active']['to'] === false
            ? AuditAction::Archived
            : AuditAction::Restored;
    }

    /**
     * @param  array<string, array{from: mixed, to: mixed}>|null  $changes
     */
    private function write(
        int $tenantId,
        AuditResource $resource,
        Model $model,
        AuditAction $action,
        ?array $changes,
    ): AuditLog {
        $actor = $this->currentActor();

        // Written *as* the tenant that changed, not as whoever happens to be in
        // the ambient context.
        //
        // The two differ more often than they look like they should. A platform
        // administrator editing a workshop's settings has no tenant of their
        // own; provisioning runs deliberately unscoped; and anything that
        // creates a second workshop does so while the first one is still
        // current. In every one of those the entry belongs to the workshop it
        // happened to — that is where somebody will go looking for it — so the
        // context is moved to match the row for exactly the length of one
        // insert, and restored afterwards even if it throws.
        //
        // Passing `tenant_id` while the context said something else would have
        // been refused by BelongsToTenant, and rightly: that guard exists so a
        // stray mass-assignment cannot plant a row in another workshop's books.
        // Satisfying it rather than working around it keeps the guard meaning
        // exactly what it says.
        return $this->tenancy->runFor($tenantId, fn () => AuditLog::create([
            'tenant_id' => $tenantId,
            'actor_id' => $actor?->id,
            // Copied, so the trail survives the user's deletion and still reads
            // as it read at the time. See the migration on why this particular
            // denormalisation is not the stored aggregate this schema forbids.
            'actor_name' => $actor?->name,
            'action' => $action,
            'resource' => $resource,
            'resource_id' => (int) $model->getKey(),
            'label' => $this->labelFor($model),
            // `changed_fields`, not `changes` — see AuditLog::changedFields() on
            // why that name collides with Eloquent's own internals.
            'changed_fields' => $changes === [] ? null : $changes,
            'context' => $this->context(),
        ]));
    }

    /* ---------------------------------------------------------------------
     | Suppression
     |-------------------------------------------------------------------- */

    /**
     * Run a callback without recording anything.
     *
     * There is exactly one legitimate reason to use this, and it is *bulk
     * machinery standing up a workshop's starting state* — see
     * {@see \App\Services\Accounting\ChartOfAccountProvisioner}, which seeds
     * fifteen accounts the moment a workshop is created. Those fifteen rows are
     * not fifteen decisions somebody made; they are one act, and it is already
     * on the trail as the creation of the workshop. Recording them individually
     * would put fifteen entries nobody chose at the top of every new workshop's
     * history, which is how a log stops being read.
     *
     * Anything else is a bug. `grep -rn 'audit->silently' app/` is the audit —
     * deliberately the same shape as `runWithoutScope`, and for the same reason:
     * a hole in a guarantee should have to name itself.
     *
     * @template TReturn
     *
     * @param  Closure(): TReturn  $callback
     * @return TReturn
     */
    public function silently(Closure $callback): mixed
    {
        $this->suppressed++;

        try {
            return $callback();
        } finally {
            $this->suppressed--;
        }
    }

    public function isSuppressed(): bool
    {
        return $this->suppressed > 0;
    }

    /**
     * Attribute everything inside the callback to a given user.
     *
     * For the contexts that have no authenticated session to read: a queued job,
     * a console command, anything running after the request that started it has
     * ended. M14's jobs capture the dispatching user and re-establish them
     * through here, so "the import created this party" names the person who
     * uploaded the file rather than nobody.
     *
     * @template TReturn
     *
     * @param  Closure(): TReturn  $callback
     * @return TReturn
     */
    public function actingAs(?User $actor, Closure $callback): mixed
    {
        $previous = $this->actor;

        $this->actor = $actor;

        try {
            return $callback();
        } finally {
            $this->actor = $previous;
        }
    }

    /* ---------------------------------------------------------------------
     | Working out what to write
     |-------------------------------------------------------------------- */

    /**
     * The fields that moved, restricted to the ones the model declares.
     *
     * Both sides are read through the casts, so an enum arrives as its value, a
     * date as a date and a boolean as a boolean — the alternative is a trail
     * that says `is_active` went from `1` to `0`, which is true and useless.
     *
     * @return array<string, array{from: mixed, to: mixed}>
     */
    private function diff(Model $model): array
    {
        $moved = $model->getChanges();
        $changes = [];

        /** @var Model&Auditable $model */
        foreach ($model->auditAttributes() as $attribute) {
            if (! array_key_exists($attribute, $moved)) {
                continue;
            }

            $from = $this->normalise($model->getOriginal($attribute));
            $to = $this->normalise($model->getAttribute($attribute));

            // A cast can flatten two different raw values to the same one — "0"
            // and 0 both become false — and an entry saying a field changed from
            // false to false is noise in the one table that must not have any.
            if ($from === $to) {
                continue;
            }

            $changes[$attribute] = ['from' => $from, 'to' => $to];
        }

        return $changes;
    }

    /**
     * Every declared field as it stands — what a deletion leaves behind.
     *
     * @return array<string, array{from: mixed, to: mixed}>
     */
    private function snapshot(Model $model): array
    {
        $snapshot = [];

        /** @var Model&Auditable $model */
        foreach ($model->auditAttributes() as $attribute) {
            $value = $this->normalise($model->getAttribute($attribute));

            if ($value === null) {
                continue;
            }

            $snapshot[$attribute] = ['from' => $value, 'to' => null];
        }

        return $snapshot;
    }

    /**
     * Everything that reaches `changes` passes through here, so the column holds
     * JSON-safe scalars and nothing else.
     */
    private function normalise(mixed $value): mixed
    {
        return match (true) {
            $value instanceof BackedEnum => $value->value,
            $value instanceof DateTimeInterface => Carbon::instance($value)->toDateTimeString(),
            is_array($value) => array_map(fn ($item) => $this->normalise($item), $value),
            is_bool($value), is_int($value), is_float($value), $value === null => $value,
            default => (string) $value,
        };
    }

    private function labelFor(Model $model): string
    {
        /** @var Model&Auditable $model */
        $label = trim($model->auditLabel());

        // The column is NOT NULL and CHECK-constrained non-empty, and a record
        // with no name of its own — an item variant saved before its label was
        // derived, say — must still leave a readable trail rather than a
        // constraint violation in the middle of somebody's edit.
        return $label === ''
            ? '#'.$model->getKey()
            : mb_substr($label, 0, 200);
    }

    private function resourceFor(Model $model): ?AuditResource
    {
        return AuditResource::forModel($model);
    }

    /**
     * Null where the change belongs to no workshop.
     *
     * The real case is a platform administrator's own user record: they hold
     * authority over every workshop and are a member of none, so an edit to
     * their name has no workshop's history to belong in. Recording it against
     * some arbitrary tenant would put a stranger in a workshop's trail; dropping
     * it is the honest outcome, and it is stated in `docs/audit-module.md`
     * rather than left to be discovered.
     */
    private function tenantFor(Model $model): ?int
    {
        /** @var Model&Auditable $model */
        return $model->auditTenantId();
    }

    private function currentActor(): ?User
    {
        if ($this->actor !== null) {
            return $this->actor;
        }

        // hasUser(), not user(): the second would ask the guard to resolve a
        // session, and there is never one to resolve here — this application
        // authenticates with a bearer token, which the JWT middleware has
        // already bound by the time any model event fires.
        return Auth::hasUser() && Auth::user() instanceof User
            ? Auth::user()
            : null;
    }

    /**
     * Ambient detail: where the change came in from, and from which address.
     *
     * Small on purpose. Anything worth filtering or reporting on gets a column;
     * this is the residue that is occasionally decisive in an investigation and
     * never worth an index.
     *
     * @return array<string, mixed>|null
     */
    private function context(): ?array
    {
        $request = request();

        // A matched route means the change arrived over HTTP. Nothing matched
        // means a console command, a queued job or a seeder — which is checked
        // this way rather than with runningInConsole(), because that is true for
        // the whole test suite including the requests it makes.
        $context = array_filter([
            'via' => $request?->route() === null ? 'console' : 'api',
            'ip' => $request?->route() === null ? null : $request->ip(),
        ], fn ($value) => $value !== null);

        return $context === [] ? null : $context;
    }
}
