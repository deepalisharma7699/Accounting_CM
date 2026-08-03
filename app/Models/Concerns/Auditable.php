<?php

namespace App\Models\Concerns;

use App\Enums\AuditAction;
use App\Enums\AuditResource;
use App\Models\AuditLog;
use App\Services\Audit\AuditRecorder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Marks a model as carrying a history — M13.
 *
 * ## Why this is a model concern and not a service call
 *
 * Because the alternative is a rule every future service has to remember. A
 * `$this->audit->record(...)` line in `PartyService::update()` is correct
 * exactly until somebody adds a second write path — an importer, a console
 * command, a repository method used from one other place — and then the trail
 * has a hole in it that nothing announces. The hole is only found when somebody
 * goes looking for a change that was never recorded, which is the one moment
 * the log had a job to do.
 *
 * Hanging it on the model events means the trail follows the *write*, wherever
 * the write comes from. M11's CSV importer creates parties through the same
 * Eloquent path the API does, so it was audited without a line being added to
 * it. That is the same reasoning `BelongsToTenant` uses for `tenant_id`, and it
 * is the reason both are traits rather than helpers.
 *
 * ## Why the attribute list is declared and not inferred
 *
 * {@see auditAttributes()} is abstract, so a model cannot use this trait
 * without saying what may be recorded. Defaulting to `$fillable` was the
 * obvious shortcut and is a deny-list wearing an allow-list's clothes: it is
 * correct until somebody adds a fillable column, and the column it eventually
 * gets wrong is a password hash or a token, sitting in a table an owner is
 * allowed to read. An explicit list cannot fail that way — a new column is
 * absent from the trail until somebody decides it belongs there, and an absence
 * is a gap rather than a leak.
 *
 * @property-read \Illuminate\Database\Eloquent\Collection<int, AuditLog> $auditLogs
 */
trait Auditable
{
    /**
     * The fields whose movements are recorded, and the only ones that may
     * appear in `audit_logs.changes`.
     *
     * @return array<int, string>
     */
    abstract public function auditAttributes(): array;

    /**
     * What to call this record on the trail — the name it went by at the moment
     * it was touched, copied onto the row so the history still reads correctly
     * after a rename or a deletion.
     */
    abstract public function auditLabel(): string;

    public static function bootAuditable(): void
    {
        static::created(function (Model $model): void {
            static::recorder()->record($model, AuditAction::Created);
        });

        // `updated`, not `updating`: the event fires after the write but before
        // Eloquent re-syncs its originals, which is the one moment both the old
        // and the new values are available — and, being inside whatever database
        // transaction the caller opened, an edit that is rolled back leaves no
        // trail claiming it happened.
        static::updated(function (Model $model): void {
            static::recorder()->recordUpdate($model);
        });

        // Soft or hard, the record has gone as far as anybody using the
        // application can tell, so both read as a deletion. This is also the one
        // action whose values are copied onto the row: nothing survives it.
        static::deleted(function (Model $model): void {
            static::recorder()->record($model, AuditAction::Deleted);
        });
    }

    /**
     * The tenant whose history this change belongs in.
     *
     * Defaults to the row's own owner, which is right for every tenant-owned
     * model. {@see \App\Models\Tenant} overrides it, because a workshop is not
     * inside a workshop — it *is* one.
     */
    public function auditTenantId(): ?int
    {
        return $this->getAttribute('tenant_id') === null
            ? null
            : (int) $this->getAttribute('tenant_id');
    }

    /**
     * This record's own history, newest first.
     *
     * Deliberately *not* a `morphMany`. Laravel's polymorphic relations store
     * `getMorphClass()` in the type column, which is the class name unless a
     * morph map is enforced globally — and a class name in a column is the
     * promise-never-to-rename that {@see \App\Enums\AuditResource} exists to
     * avoid. A `hasMany` narrowed by the enum's stable key says the same thing
     * without asking the framework to own the vocabulary.
     *
     * @return HasMany<AuditLog, $this>
     */
    public function auditLogs(): HasMany
    {
        return $this->hasMany(AuditLog::class, 'resource_id')
            ->where('resource', AuditResource::forModel($this)?->value)
            ->newestFirst();
    }

    private static function recorder(): AuditRecorder
    {
        return app(AuditRecorder::class);
    }
}
