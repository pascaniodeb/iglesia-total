<?php

namespace App\Modules\Integrity\Domain\Enums;

/**
 * Through which door a fact entered: a signed-in user, the operator or scheduler (console),
 * or someone not signed in (only for security facts such as a failed sign-in).
 */
enum ActivityChannel: string
{
    case Web = 'web';
    case System = 'system';
    case Guest = 'guest';
}
