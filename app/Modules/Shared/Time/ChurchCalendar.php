<?php

namespace App\Modules\Shared\Time;

use DateTimeImmutable;

/**
 * The church's calendar day. The application runs in UTC, but "today" is the congregation's
 * (Venezuela): after 8 p.m. local time UTC is already tomorrow.
 */
final class ChurchCalendar
{
    public const string TIME_ZONE = 'America/Caracas';

    public static function today(): DateTimeImmutable
    {
        return new DateTimeImmutable(now(self::TIME_ZONE)->toDateString());
    }
}
