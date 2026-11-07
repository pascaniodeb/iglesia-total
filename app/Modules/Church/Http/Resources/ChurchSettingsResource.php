<?php

namespace App\Modules\Church\Http\Resources;

use App\Modules\Church\Infrastructure\Models\ChurchSetting;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin ChurchSetting */
class ChurchSettingsResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'name' => $this->name,
            'pastor' => $this->pastor,
            'code' => $this->code,
            'rif' => $this->rif,
            'denomination' => $this->denomination,
            'headquarters' => $this->headquarters,
            'registration_note' => $this->registration_note,
            'denomination_rif' => $this->denomination_rif,
            'logo_light' => $this->asset('logo_light'),
            'logo_dark' => $this->asset('logo_dark'),
            'favicon' => $this->asset('favicon'),
        ];
    }

    /**
     * @return array{url: string, mime: string, size: int, sha256: string}|null
     */
    private function asset(string $kind): ?array
    {
        $path = $this->getAttribute("{$kind}_path");

        if (! is_string($path)) {
            return null;
        }

        return [
            'url' => route('church.settings.branding', str_replace('_', '-', $kind)),
            'mime' => (string) $this->getAttribute("{$kind}_mime"),
            'size' => (int) $this->getAttribute("{$kind}_size_bytes"),
            'sha256' => (string) $this->getAttribute("{$kind}_sha256"),
        ];
    }
}
