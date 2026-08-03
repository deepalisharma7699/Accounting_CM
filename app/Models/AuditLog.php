<?php

namespace App\Models;

use App\Enums\AuditAction;
use App\Enums\AuditResource;
use App\Exceptions\Audit\AuditImmutableException;
use App\Models\Concerns\BelongsToTenant;
use App\Services\Audit\AuditRecorder;
use DateTimeInterface;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * One entry in a workshop's trail: who changed what, when — M13.
 *
 * Three rules hold without exception, and they are the ledger's three, restated
 * about master data rather than about money:
 *
 *   1. Rows arrive only through {@see AuditRecorder}, which is driven by model
 *      events on {@see \App\Models\Concerns\Auditable}. No service writes here
 *      directly, so no service can forget to.
 *   2. A row is never updated and never deleted — guarded below. There is no
 *      correcting a trail; a wrong entry is a bug in the recorder, and the fix
 *      is upstream.
 *   3. Every row is tenant-scoped, and scoped to the tenant the change was made
 *      *to*. A platform administrator editing a workshop's financial year writes
 *      into that workshop's history, because that is where somebody will look.
 *
 * @property int $id
 * @property int $tenant_id
 * @property int|null $actor_id
 * @property string|null $actor_name
 * @property AuditAction $action
 * @property AuditResource $resource
 * @property int $resource_id
 * @property string $label
 * @property array<string, array{from: mixed, to: mixed}>|null $changed_fields
 * @property array<string, mixed>|null $context
 * @property Carbon $created_at
 */
#[Fillable([
    'tenant_id', 'actor_id', 'actor_name', 'action',
    'resource', 'resource_id', 'label', 'changed_fields', 'context',
])]
class AuditLog extends Model
{
    use BelongsToTenant;

    /**
     * An entry is written once and never touched again, so a column claiming to
     * record its last modification would be a permanent lie — the same reasoning
     * as a journal entry and a stock movement.
     */
    public const UPDATED_AT = null;

    /**
     * Deliberately no HasFactory, exactly as {@see JournalEntry} and
     * {@see StockMovement} have none. An audit row that did not come from the
     * recorder is a claim about something that never happened, and a test that
     * manufactured one would be asserting against its own fixture rather than
     * against the application. Tests here make history the way users do: by
     * editing a party.
     */

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'action' => AuditAction::class,
            'resource' => AuditResource::class,
            'changed_fields' => 'array',
            'context' => 'array',
            'created_at' => 'datetime',
        ];
    }

    protected static function booted(): void
    {
        static::updating(function (self $entry): void {
            throw AuditImmutableException::updating($entry->id);
        });

        static::deleting(function (self $entry): void {
            throw AuditImmutableException::deleting($entry->id);
        });
    }

    /* ---------------------------------------------------------------------
     | Relations
     |-------------------------------------------------------------------- */

    /**
     * Who did it — or null, once they have been deleted. The name survives them
     * on `actor_name`, which is the whole reason that column exists.
     *
     * @return BelongsTo<User, $this>
     */
    public function actor(): BelongsTo
    {
        return $this->belongsTo(User::class, 'actor_id');
    }

    /* ---------------------------------------------------------------------
     | Reading
     |-------------------------------------------------------------------- */

    /**
     * The name to show for whoever did this.
     *
     * Prefers the copy taken at the time over the live user, and does so even
     * when the user still exists: the trail should read as it read then. Falls
     * back to a plain statement rather than to an empty cell, because "nobody"
     * and "we did not record it" are different things and a blank says neither.
     */
    public function actorLabel(): string
    {
        return $this->actor_name
            ?? $this->actor?->name
            ?? 'The system';
    }

    /**
     * The fields this entry says moved, as a list, by name.
     *
     * Sorted rather than left as stored, because MySQL's JSON type does not
     * preserve the order keys were written in — it re-orders them by length and
     * then lexicographically. That is deterministic but arbitrary, and it means
     * a screen reading the map straight back shows `to` before `from` and
     * `gstin` before `address` for no reason a reader could infer. Sorting by
     * name is equally deterministic and at least explicable.
     *
     * Note the column is `changed_fields`, not `changes`: `Model::$changes` is
     * Eloquent's own protected property, and inside this class `$this->changes`
     * would resolve to *that* — the framework's record of what the last save
     * modified, empty on a freshly loaded row. It would fail without an error,
     * which is the worst way for an audit trail to fail. See the migration.
     *
     * @return array<int, array{field: string, from: mixed, to: mixed}>
     */
    public function changedFields(): array
    {
        $changes = $this->changed_fields ?? [];

        ksort($changes);

        $rows = [];

        foreach ($changes as $field => $movement) {
            $rows[] = [
                'field' => $field,
                'from' => $movement['from'] ?? null,
                'to' => $movement['to'] ?? null,
            ];
        }

        return $rows;
    }

    /* ---------------------------------------------------------------------
     | Scopes
     |-------------------------------------------------------------------- */

    /**
     * @param  Builder<self>  $query
     * @return Builder<self>
     */
    public function scopeForResource(Builder $query, AuditResource|string $resource, ?int $id = null): Builder
    {
        $query->where('resource', $resource instanceof AuditResource ? $resource->value : $resource);

        return $id === null ? $query : $query->where('resource_id', $id);
    }

    /**
     * @param  Builder<self>  $query
     * @return Builder<self>
     */
    public function scopeForActor(Builder $query, int $actorId): Builder
    {
        return $query->where('actor_id', $actorId);
    }

    /**
     * @param  Builder<self>  $query
     * @return Builder<self>
     */
    public function scopeForAction(Builder $query, AuditAction|string $action): Builder
    {
        return $query->where('action', $action instanceof AuditAction ? $action->value : $action);
    }

    /**
     * @param  Builder<self>  $query
     * @return Builder<self>
     */
    public function scopeFrom(Builder $query, DateTimeInterface|string $date): Builder
    {
        return $query->whereDate('created_at', '>=', $date);
    }

    /**
     * @param  Builder<self>  $query
     * @return Builder<self>
     */
    public function scopeUpTo(Builder $query, DateTimeInterface|string $date): Builder
    {
        return $query->whereDate('created_at', '<=', $date);
    }

    /**
     * Newest first — the order a history is read in, and the opposite of the day
     * book's. A trail is scanned for "what happened recently"; a day book is
     * walked forwards because that is the order the day happened in.
     *
     * Tie-broken on id rather than left to the timestamp alone: several changes
     * inside one request share a second, and a history that reshuffles them
     * between two loads of the same page reads as unreliable.
     *
     * @param  Builder<self>  $query
     * @return Builder<self>
     */
    public function scopeNewestFirst(Builder $query): Builder
    {
        return $query->orderByDesc('created_at')->orderByDesc('id');
    }
}
