<?php

namespace App\Enums;

use App\Concerns\Enums\HasOptions;

enum MemberMaritalStatus: string
{
    use HasOptions;

    case Single = 'soltero';
    case Married = 'casado';
    case CommonLawUnion = 'union_estable_de_hecho';
    case Widowed = 'viudo';
    case Divorced = 'divorciado';

    public function label(): string
    {
        return match ($this) {
            self::Single => __('Single'),
            self::Married => __('Married'),
            self::CommonLawUnion => __('Common-law union'),
            self::Widowed => __('Widowed'),
            self::Divorced => __('Divorced'),
        };
    }
}
