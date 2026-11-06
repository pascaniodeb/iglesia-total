<?php

use App\Modules\Integrity\IntegrityServiceProvider;
use App\Providers\AppServiceProvider;
use App\Providers\FortifyServiceProvider;

return [
    AppServiceProvider::class,
    FortifyServiceProvider::class,
    IntegrityServiceProvider::class,
];
