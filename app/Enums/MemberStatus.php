<?php

namespace App\Enums;

enum MemberStatus: string
{
    case Active = 'activo';
    case Transferred = 'trasladado';
    case Inactive = 'inactivo';
    case Deceased = 'fallecido';
}
