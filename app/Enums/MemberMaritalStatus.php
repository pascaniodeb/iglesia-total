<?php

namespace App\Enums;

enum MemberMaritalStatus: string
{
    case Single = 'soltero';
    case Married = 'casado';
    case CommonLawUnion = 'union_estable_de_hecho';
    case Widowed = 'viudo';
    case Divorced = 'divorciado';
}
