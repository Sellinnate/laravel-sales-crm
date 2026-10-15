<?php

namespace Sellinnate\SalesCrm\Http\Controllers\Api;

use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Http\Request;
use Sellinnate\SalesCrm\Authorization\Access;

/**
 * The user of a request of the JSON API, who may access the CRM (403 otherwise).
 *
 * @internal
 */
final class ApiUser
{
    public static function from(Request $request, Access $access): Authenticatable
    {
        $user = $request->user();

        abort_unless($user instanceof Authenticatable && $access->canAccess($user), 403);

        return $user;
    }
}
