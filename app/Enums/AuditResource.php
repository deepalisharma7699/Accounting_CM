<?php

namespace App\Enums;

use App\Models\Attachment;
use App\Models\ChartOfAccount;
use App\Models\Employee;
use App\Models\Item;
use App\Models\ItemAttribute;
use App\Models\ItemBrand;
use App\Models\ItemCategory;
use App\Models\ItemVariant;
use App\Models\Party;
use App\Models\StaffDesignation;
use App\Models\Tenant;
use App\Models\TransactionStaff;
use App\Models\Unit;
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
     * The Category Master, and the attribute definitions under it.
     *
     * Audited for a reason the other master data does not share: a category is a
     * *template*, so editing one changes what every product filed under it is
     * asked to record. Switching an attribute off, or making a required field
     * optional, silently changes the shape of records created afterwards — and
     * the products themselves show no edit at all, because none of them was
     * touched. Without this the trail would say nobody changed anything.
     */
    case Category = 'category';
    case CategoryAttribute = 'category_attribute';

    /**
     * The Brand Master. Audited for a narrower reason than the category is:
     * renaming a brand renames it on every product that carries it at once, and
     * none of those products shows an edit — so without an entry here the trail
     * would say nobody touched a catalogue that changed under everyone's hands.
     */
    case Brand = 'brand';

    /**
     * The Unit Master. Audited because a unit's *scale* decides whether a
     * fraction is a legitimate quantity, so widening one retrospectively permits
     * a half-bearing that was refused an hour earlier.
     *
     * The code is write-once and therefore never appears as a change here, which
     * is the point: a unit that could be renamed from 'kg' to 'metre' would
     * reinterpret every quantity ever recorded against it.
     */
    case Unit = 'unit';

    /**
     * M14's stored files. Audited because deleting the photograph of an invoice
     * is precisely the act a trail exists to record — the file is evidence, and
     * unlike master data there is nothing left behind when it goes.
     */
    case Attachment = 'attachment';

    /**
     * An employee — M22. Audited because their `pay_rate` and `salary_basis` are
     * what payroll multiplies by: raising a wage retrospectively changes what
     * every month run afterwards pays out, and a posted run carries only the
     * figure it used, not the reason it was that figure. Without an entry here
     * the trail would say nobody decided anything.
     *
     * `left_on` is on the list for a related reason: it is what takes somebody
     * off the payroll, and a date typed a month early is an underpayment nobody
     * would think to look for.
     */
    case Employee = 'employee';

    /**
     * The Designation Master. Audited on exactly the reasoning the Brand Master
     * carries: renaming one renames it on every employee filed under it at once,
     * and none of those employees shows an edit.
     */
    case StaffDesignation = 'staff_designation';

    /**
     * Who a sale was credited to — M22.
     *
     * The one audited row in this application whose *point* is that it can be
     * changed. A posted invoice is immutable, and correcting the fitter's name
     * moves no figure on it — so the edit is allowed, and this is the only place
     * it is written down. "The report says Ramesh did forty jobs last month"
     * would otherwise be unanswerable the moment anybody doubted it.
     */
    case SaleAttribution = 'sale_attribution';

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
            self::Category => ItemCategory::class,
            self::CategoryAttribute => ItemAttribute::class,
            self::Brand => ItemBrand::class,
            self::Unit => Unit::class,
            self::User => User::class,
            self::Attachment => Attachment::class,
            self::Employee => Employee::class,
            self::StaffDesignation => StaffDesignation::class,
            self::SaleAttribution => TransactionStaff::class,
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
            self::Category => 'Category',
            self::CategoryAttribute => 'Category field',
            self::Brand => 'Brand',
            self::Unit => 'Unit',
            self::User => 'User',
            self::Attachment => 'Attachment',
            self::Employee => 'Employee',
            self::StaffDesignation => 'Designation',
            self::SaleAttribution => 'Sale attribution',
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
            // The masters live inside the Items workspace rather than on pages
            // of their own — there is one page in this product — so the trail
            // links to the module and names the record.
            self::Category, self::CategoryAttribute, self::Brand, self::Unit => '/items',
            self::User => '/users',
            self::Attachment => '/uploads',
            // The designation master lives inside the Staff workspace rather
            // than on a page of its own, exactly as the catalogue masters live
            // inside Items — so both link to the module and name the record.
            self::Employee, self::StaffDesignation => '/staff',
            // The correction is made on the invoice, not on the staff list — a
            // reader following this row wants the document whose credit moved.
            self::SaleAttribution => '/sales',
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
