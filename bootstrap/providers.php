<?php

use App\Modules\Accounting\AccountingServiceProvider;
use App\Modules\Integrity\IntegrityServiceProvider;
use App\Modules\Members\MembersServiceProvider;
use App\Modules\Treasury\TreasuryServiceProvider;
use App\Providers\AppServiceProvider;
use App\Providers\FortifyServiceProvider;

return [
    AppServiceProvider::class,
    FortifyServiceProvider::class,
    IntegrityServiceProvider::class,
    MembersServiceProvider::class,
    AccountingServiceProvider::class,
    TreasuryServiceProvider::class,
];
