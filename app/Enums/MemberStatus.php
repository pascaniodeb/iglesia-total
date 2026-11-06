<?php

namespace App\Enums;

use App\Concerns\Enums\HasOptions;

enum MemberStatus: string
{
    use HasOptions;

    case Active = 'activo';
    case Transferred = 'trasladado';
    case Inactive = 'inactivo';
    case Deceased = 'fallecido';

    public function label(): string
    {
        return match ($this) {
            self::Active => __('Active'),
            self::Transferred => __('Transferred'),
            self::Inactive => __('Inactive'),
            self::Deceased => __('Deceased'),
        };
    }
}
