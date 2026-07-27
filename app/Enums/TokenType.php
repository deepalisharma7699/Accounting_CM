<?php

namespace App\Enums;

/**
 * Value of the custom `typ` claim. Access and refresh tokens are signed with
 * the same key, so the type claim is what stops a refresh token from being
 * accepted as an access token (and vice versa).
 */
enum TokenType: string
{
    case Access = 'access';
    case Refresh = 'refresh';
}
