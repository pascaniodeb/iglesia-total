<?php

namespace App\Modules\Church;

use App\Modules\Church\Application\ChurchIdentity;
use App\Modules\Church\Application\ChurchLetterhead;
use App\Modules\Church\Infrastructure\ChurchIdentityDatabase;
use App\Modules\Church\Infrastructure\Pdf\FpdfChurchLetterhead;
use Illuminate\Support\ServiceProvider;

class ChurchServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->bind(ChurchIdentity::class, ChurchIdentityDatabase::class);
        $this->app->bind(ChurchLetterhead::class, FpdfChurchLetterhead::class);
    }
}
