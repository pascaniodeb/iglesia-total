<?php

namespace App\Enums;

enum MemberDocumentType: string
{
    case Venezuelan = 'V';
    case Foreigner = 'E';
    case Passport = 'pasaporte';
    case None = 'sin_documento';
}
