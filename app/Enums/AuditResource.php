<?php

namespace App\Enums;

use App\Models\Attachment;
use App\Models\ChartOfAccount;
use App\Models\Item;
use App\Models\ItemVariant;
use App\Models\Party;
use App\Models\Tenant;
use App\Models\User;
use Illuminate\Database\Eloquent\Model;

/**
 * The kinds of record the audit log covers — M13.
 *
 * ## Why a key and not a class name
 *
 * `audit_logs.resource` stores one of these strings, never `App\Models\Party`.
 * A class name in a database column is a promise never to move or rename the
 * class, and the promise is broken silently: the rows do not fail, they simply
 * stop resolving, and the trail from before the rename becomes unreadable
 * exactly when somebody needs it. The key is the stable name; the class is an
 * implementation detail this enum owns.
 *
 * ## What is deliberately absent
 *
 * **Transactions, journal entries and stock movements.** Not an oversight — the
 * point of M13. A posted transaction cannot be edited or deleted at all, journal
 * entries and stock movements refuse an UPDATE on the model, and `created_by`
 * and `posted_at` already sit on every transaction. "Who changed this figure"
 * has no answer because nothing changes a figure, and an audit row restating
 * what the ledger already records would be a second copy of the truth — which is
 * the one thing this codebase refuses everywhere else.
 *
 * **Roles and permissions.** Platform-defined rather than workshop-owned: a role
 * belongs to every tenant at once, so there is no workshop whose history it
 * belongs in. See {@see \App\Enums\PermissionResource::Audit}.
 */
enum AuditResource: string
{
    /**
     * The workshop itself: its name, GSTIN, address, financial year, timezone
     * and go-live date. `workspace` rather than `tenant`, matching the
     * permission and the screen — a workshop editing its own settings is not
     * administering a tenant.
     */
    case Workspace = 'workspace';

    case Account = 'account';
    case Party = 'party';
    case Item = 'item';
    case Variant = 'variant';
    case User = 'user';

    /**
     * M14's stored files. Audited because deleting the photograph of an invoice
     * is precisely the act a trail exists to record — the file is evidence, and
     * unlike master data there is nothing left behind when it goes.
     */
    case Attachment = 'attachment';

    /**
     * @return class-string<Model>
     */
    public function modelClass(): string
    {
        return match ($this) {
            self::Workspace => Tenant::class,
            self::Account => ChartOfAccount::class,
            self::Party => Party::class,
            self::Item => Item::class,
            self::Variant => ItemVariant::class,
            self::User => User::class,
            self::Attachment => Attachment::class,
        };
    }

    public function label(): string
    {
        return match ($this) {
            self::Workspace => 'Workshop settings',
            self::Account => 'Account',
            self::Party => 'Party',
            self::Item => 'Item',
            self::Variant => 'Variant',
            self::User => 'User',
            self::Attachment => 'Attachment',
        };
    }

    /**
     * Where the screens link a row back to. Null where there is no page that
     * addresses one record — the parties and items screens are lists with their
     * own search, so the trail names the record rather than pretending to a
     * deep link that does not exist.
     */
    public function route(): ?string
    {
        return match ($this) {
            self::Workspace => '/workspace',
            self::Account => '/accounts',
            self::Party => '/parties',
            self::Item, self::Variant => '/items',
            self::User => '/users',
            self::Attachment => '/uploads',
        };
    }

    public static function forModel(Model $model): ?self
    {
        foreach (self::cases() as $case) {
            if ($model instanceof ($case->modelClass())) {
                return $case;
            }
        }

        return null;
    }

    /**
     * @return array<int, string>
     */
    public static function values(): array
    {
        return array_column(self::cases(), 'value');
    }

    /**
     * @return array<int, array{value: string, label: string}>
     */
    public static function catalogue(): array
    {
        return array_map(
            fn (self $resource) => ['value' => $resource->value, 'label' => $resource->label()],
            self::cases(),
        );
    }
}
