<?php

namespace App\Enums;

use App\Concerns\Enums\HasOptions;

enum MemberSex: string
{
    use HasOptions;

    case Female = 'femenino';
    case Male = 'masculino';

    public function label(): string
    {
        return match ($this) {
            self::Female => __('Female'),
            self::Male => __('Male'),
        };
    }
}
