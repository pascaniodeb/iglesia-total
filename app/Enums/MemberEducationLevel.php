<?php

namespace App\Enums;

enum MemberEducationLevel: string
{
    case None = 'ninguno';
    case Primary = 'primaria';
    case HighSchool = 'bachillerato';
    case Technical = 'tsu';
    case University = 'universitario';
    case Postgraduate = 'postgrado';
}
