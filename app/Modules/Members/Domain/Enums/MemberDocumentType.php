<?php

namespace App\Modules\Members\Domain\Enums;

use App\Modules\Shared\Concerns\HasOptions;

enum MemberDocumentType: string
{
    use HasOptions;

    case Venezuelan = 'V';
    case Foreigner = 'E';
    case Passport = 'pasaporte';
    case None = 'sin_documento';

    public function label(): string
    {
        return match ($this) {
            self::Venezuelan => 'V',
            self::Foreigner => 'E',
            self::Passport => __('Passport'),
            self::None => __('No document'),
        };
    }
}
