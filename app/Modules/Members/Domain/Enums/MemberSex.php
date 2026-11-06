<?php

namespace App\Modules\Members\Domain\Enums;

use App\Modules\Shared\Concerns\HasOptions;

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
