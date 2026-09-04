<?php

namespace App\Models;

use App\Enums\TenantStatus;
use App\Models\Concerns\Auditable;
use Carbon\CarbonImmutable;
use Database\Factories\TenantFactory;
use DateTimeInterface;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Carbon;

/**
 * One workshop. The root of every tenant-scoped record in the system.
 *
 * Tenant itself is *not* tenant-scoped — it is the thing being scoped to, and
 * only platform super-admins ever list it.
 *
 * @property int $id
 * @property string $name
 * @property string $slug
 * @property string|null $gstin
 * @property string|null $address
 * @property string|null $state_code
 * @property TenantStatus $status
 * @property int $financial_year_start_month
 * @property string $timezone
 * @property string $currency
 * @property Carbon|null $books_start_date
 * @property int|null $payment_due_days
 * @property bool $allow_negative_stock
 * @property bool $round_off_invoices
 */
#[Fillable([
    'name', 'slug', 'gstin', 'address', 'state_code', 'status',
    'financial_year_start_month', 'timezone', 'currency', 'books_start_date',
    'payment_due_days', 'allow_negative_stock', 'round_off_invoices',
])]
class Tenant extends Model
{
    /** @use HasFactory<TenantFactory> */
    use Auditable, HasFactory, SoftDeletes;

    /**
     * A workshop is not *inside* a workshop — it is one. Every other auditable
     * model reads its owner off `tenant_id`; this one is the owner.
     *
     * The consequence is the useful part: a platform administrator changing a
     * workshop's status writes into that workshop's own history, so the entry is
     * found by the people it happened to rather than living in a platform log
     * they cannot see.
     */
    public function auditTenantId(): ?int
    {
        return $this->getKey() === null ? null : (int) $this->getKey();
    }

    /**
     * `financial_year_start_month`, `timezone` and `books_start_date` are the
     * three that matter most on this list, and they are the reason M13 exists at
     * all. None of them changes a posted figure; all three change what every
     * period report *means*, silently and retrospectively. A P&L that looks
     * wrong three months later is explained by one row here and by nothing in
     * the ledger.
     *
     * `slug` and `currency` are listed although neither is editable today —
     * a trail whose absence is enforced only by a service is a trail that goes
     * quiet the day the service changes.
     *
     * @return array<int, string>
     */
    public function auditAttributes(): array
    {
        return [
            'name', 'slug', 'gstin', 'address', 'state_code', 'status',
            'financial_year_start_month', 'timezone', 'currency', 'books_start_date',
            // Changes nothing about a posted figure and changes which bills the
            // workshop is told to chase — which is precisely the kind of setting
            // M13 exists to keep a record of.
            'payment_due_days',
            // And the one that decides whether a bill for stock the shelf does
            // not hold is refused or merely flagged. "Why did this go through?"
            // is answered by one row here and by nothing in the ledger.
            'allow_negative_stock',
            // And the one that decides whether the customer is charged
            // ₹1,062.36 or ₹1,062. "Why is this invoice a rupee off the
            // quotation?" is a question about a date and a setting, and the
            // setting is only answerable from here.
            'round_off_invoices',
        ];
    }

    public function auditLabel(): string
    {
        return $this->name;
    }

    /**
     * Structure of a GSTIN: 2-digit state code, 10-character PAN, a 1-9/A-Z
     * entity number, a literal "Z", then a checksum character.
     *
     * Shape only — the checksum itself is not verified here, because a
     * mistyped GSTIN is caught by GST filing long before this validation would
     * matter, and rejecting a legitimate one at sign-up is the worse failure.
     */
    public const GSTIN_PATTERN = '/^[0-9]{2}[A-Z]{5}[0-9]{4}[A-Z][1-9A-Z]Z[0-9A-Z]$/';

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'status' => TenantStatus::class,
            'financial_year_start_month' => 'integer',
            'books_start_date' => 'date',
            'payment_due_days' => 'integer',
            'allow_negative_stock' => 'boolean',
            'round_off_invoices' => 'boolean',
        ];
    }

    /**
     * The day a bill dated `$date` stops being merely unpaid and starts being
     * overdue — M16.
     *
     * Null where the workshop has not set terms, and that is the answer rather
     * than a missing one: a counter trade settles on the spot, and an ageing
     * computed against terms nobody agreed to would only mislead. Nothing is
     * reported overdue until somebody says what overdue means here.
     */
    public function dueDateFor(DateTimeInterface $date): ?CarbonImmutable
    {
        return $this->payment_due_days === null
            ? null
            : CarbonImmutable::instance($date)->startOfDay()->addDays($this->payment_due_days);
    }

    /**
     * The financial year containing a given date, as [start, end].
     *
     * With an April start, 2026-02-10 belongs to the year that opened on
     * 2025-04-01 — which is exactly the off-by-one every hand-rolled report
     * gets wrong, so it is computed in one place.
     *
     * @return array{0: CarbonImmutable, 1: CarbonImmutable}
     */
    public function financialYearFor(?DateTimeInterface $date = null): array
    {
        $date = ($date === null
            ? CarbonImmutable::now($this->timezone)
            : CarbonImmutable::instance($date))->startOfDay();

        $start = $date->setDate($date->year, $this->financial_year_start_month, 1);

        if ($date->lessThan($start)) {
            $start = $start->subYear();
        }

        return [$start, $start->addYear()->subDay()];
    }

    /**
     * Are the books open on this date? False before go-live: that period
     * belongs to whatever the workshop used previously, and its closing
     * position arrives as opening balances instead.
     */
    public function acceptsPostingOn(DateTimeInterface $date): bool
    {
        return $this->books_start_date === null
            || ! CarbonImmutable::instance($date)->startOfDay()
                ->lessThan(CarbonImmutable::instance($this->books_start_date)->startOfDay());
    }

    /**
     * @return HasMany<User, $this>
     */
    public function users(): HasMany
    {
        return $this->hasMany(User::class);
    }

    public function isActive(): bool
    {
        return $this->status->canOperate();
    }

    /**
     * Canonical slug for a workshop name: "Sharma Electricals" -> "sharma-electricals".
     */
    public static function slugFor(string $name): string
    {
        return str($name)->slug()->limit(150, '')->value();
    }

    public function getRouteKeyName(): string
    {
        return 'id';
    }
}
