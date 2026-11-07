<?php

namespace App\Modules\Church\Infrastructure;

use App\Modules\Church\Application\ChurchBranding;
use App\Modules\Church\Application\ChurchIdentity;
use App\Modules\Church\Application\ChurchProfile;
use App\Modules\Church\Infrastructure\Models\ChurchSetting;

final class ChurchIdentityDatabase implements ChurchIdentity
{
    public function profile(): ?ChurchProfile
    {
        $settings = ChurchSetting::query()->find(1);

        if ($settings === null) {
            return null;
        }

        return new ChurchProfile(
            name: $settings->name,
            pastor: $settings->pastor,
            code: $settings->code,
            rif: $settings->rif,
            denomination: $settings->denomination,
            headquarters: $settings->headquarters,
            registrationNote: $settings->registration_note,
            denominationRif: $settings->denomination_rif,
            lightLogo: $this->branding($settings, 'logo_light'),
            darkLogo: $this->branding($settings, 'logo_dark'),
            favicon: $this->branding($settings, 'favicon'),
        );
    }

    private function branding(ChurchSetting $settings, string $prefix): ?ChurchBranding
    {
        $path = $settings->getAttribute("{$prefix}_path");

        if (! is_string($path)) {
            return null;
        }

        return new ChurchBranding(
            path: $path,
            mimeType: (string) $settings->getAttribute("{$prefix}_mime"),
            sizeBytes: (int) $settings->getAttribute("{$prefix}_size_bytes"),
            sha256: (string) $settings->getAttribute("{$prefix}_sha256"),
        );
    }
}
