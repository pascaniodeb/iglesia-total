<?php

namespace App\Modules\Treasury\Domain\Enums;

/**
 * Where a day's rate came from: the official sources, a manual correction (prevails over the
 * sync) or the old system.
 */
enum RateOrigin: string
{
    case Market = 'market';
    case Manual = 'manual';
    case Legacy = 'legacy';

    public function label(): string
    {
        return match ($this) {
            self::Market => __('BCV and TRM'),
            self::Manual => __('Manual'),
            self::Legacy => __('Old system'),
        };
    }
}
