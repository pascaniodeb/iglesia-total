<?php

namespace App\Modules\Church\Application;

final readonly class ChurchProfile
{
    public function __construct(
        public string $name,
        public string $pastor,
        public string $code,
        public string $rif,
        public string $denomination,
        public string $headquarters,
        public string $registrationNote,
        public string $denominationRif,
        public ?ChurchBranding $lightLogo,
        public ?ChurchBranding $darkLogo,
        public ?ChurchBranding $favicon,
    ) {}
}
