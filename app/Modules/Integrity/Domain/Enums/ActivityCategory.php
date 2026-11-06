<?php

namespace App\Modules\Integrity\Domain\Enums;

use App\Modules\Shared\Concerns\HasOptions;

/**
 * What kind of fact an activity log entry records.
 */
enum ActivityCategory: string
{
    use HasOptions;

    /** A write to the church's data (members, offerings, entries, closures...). */
    case Data = 'data';

    /** Sign-in, sign-out, failed attempts, password and second-factor changes. */
    case Security = 'security';

    /** A read of sensitive material (a PDF, a member's card) that must be traceable. */
    case Access = 'access';

    /** Imports, verifications and other commands run by the operator or the scheduler. */
    case System = 'system';

    public function label(): string
    {
        return match ($this) {
            self::Data => __('Data'),
            self::Security => __('Security'),
            self::Access => __('Access'),
            self::System => __('System'),
        };
    }
}
