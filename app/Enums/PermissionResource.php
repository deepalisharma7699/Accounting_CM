<?php

namespace App\Enums;

/**
 * Resources owned by this module. Other modules add their own constants /
 * seed rows; the permission guard accepts any string, so nothing here has to
 * change when a new resource appears elsewhere in the application.
 */
enum PermissionResource: string
{
    case Users = 'USERS';
    case Roles = 'ROLES';
    case Permissions = 'PERMISSIONS';

    /**
     * @return array<int, string>
     */
    public static function values(): array
    {
        return array_column(self::cases(), 'value');
    }
}
