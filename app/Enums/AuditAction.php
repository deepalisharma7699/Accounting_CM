<?php

namespace App\Enums;

/**
 * What happened to a record — M13.
 *
 * Five values rather than the obvious three. `archived` and `restored` are
 * *derived* from an update that flipped `is_active`, and they exist because
 * archiving is the closest thing this product has to a deletion: an account, a
 * party or an item that has been transacted with is never removed, it is
 * switched off, and "who took our biggest supplier off the list" is the question
 * somebody actually asks. Filed under `updated` it would be one row among forty
 * field edits and effectively invisible.
 */
enum AuditAction: string
{
    case Created = 'created';
    case Updated = 'updated';

    /**
     * `is_active` went true → false. The soft deletion this product prefers to
     * a hard one, because a record with entries behind it must survive or those
     * entries lose the name that explains them.
     */
    case Archived = 'archived';

    /** `is_active` went false → true. */
    case Restored = 'restored';

    /**
     * Actually gone. Only ever reaches a record nothing points at — see the
     * DELETE routes in `routes/api.php`, every one of which refuses once the
     * record has been referenced.
     */
    case Deleted = 'deleted';

    public function label(): string
    {
        return match ($this) {
            self::Created => 'Created',
            self::Updated => 'Edited',
            self::Archived => 'Archived',
            self::Restored => 'Restored',
            self::Deleted => 'Deleted',
        };
    }

    /**
     * Whether the row carries a `changes` map.
     *
     * True for everything except a creation, and the asymmetry is deliberate.
     * A creation needs no snapshot because *the record is the snapshot*: it
     * still exists, and every edit since is on the trail, so the original state
     * can be reconstructed by walking backwards. A deletion is the opposite
     * case — nothing survives it — so the values are copied onto the row as
     * they stood, or the trail would say a party was deleted and be unable to
     * say which one beyond its name.
     */
    public function carriesChanges(): bool
    {
        return $this !== self::Created;
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
            fn (self $action) => ['value' => $action->value, 'label' => $action->label()],
            self::cases(),
        );
    }
}
