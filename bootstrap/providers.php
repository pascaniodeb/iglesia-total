<?php

use App\Modules\Accounting\AccountingServiceProvider;
use App\Modules\Church\ChurchServiceProvider;
use App\Modules\Integrity\IntegrityServiceProvider;
use App\Modules\Members\MembersServiceProvider;
use App\Modules\Treasury\TreasuryServiceProvider;
use App\Providers\AppServiceProvider;
use App\Providers\FortifyServiceProvider;

return [
    AppServiceProvider::class,
    FortifyServiceProvider::class,
    IntegrityServiceProvider::class,
    ChurchServiceProvider::class,
    MembersServiceProvider::class,
    AccountingServiceProvider::class,
    TreasuryServiceProvider::class,
];
