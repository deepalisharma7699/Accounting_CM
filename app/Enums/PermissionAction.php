<?php

namespace App\Enums;

/**
 * Canonical action verbs. Permission checks are string based so a module can
 * introduce its own verb without touching this enum, but everything shipped
 * with the platform should use one of these.
 */
enum PermissionAction: string
{
    case Read = 'READ';
    case Write = 'WRITE';
    case Update = 'UPDATE';
    case Delete = 'DELETE';
    case Manage = 'MANAGE';

    /**
     * @return array<int, string>
     */
    public static function values(): array
    {
        return array_column(self::cases(), 'value');
    }
}
