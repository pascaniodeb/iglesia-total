<?php

namespace App\Modules\Church\Application;

/** Public read contract for the congregation identity. */
interface ChurchIdentity
{
    public function profile(): ?ChurchProfile;
}
