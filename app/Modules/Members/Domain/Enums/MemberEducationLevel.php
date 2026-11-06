<?php

namespace App\Modules\Members\Domain\Enums;

use App\Modules\Shared\Concerns\HasOptions;

enum MemberEducationLevel: string
{
    use HasOptions;

    case None = 'ninguno';
    case Primary = 'primaria';
    case HighSchool = 'bachillerato';
    case Technical = 'tsu';
    case University = 'universitario';
    case Postgraduate = 'postgrado';

    public function label(): string
    {
        return match ($this) {
            self::None => __('No schooling'),
            self::Primary => __('Primary'),
            self::HighSchool => __('High school'),
            self::Technical => __('Technical (TSU)'),
            self::University => __('University'),
            self::Postgraduate => __('Postgraduate'),
        };
    }
}
