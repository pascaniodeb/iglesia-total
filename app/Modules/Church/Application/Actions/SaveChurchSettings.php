<?php

namespace App\Modules\Church\Application\Actions;

use App\Modules\Church\Infrastructure\Models\ChurchSetting;
use App\Modules\Church\Infrastructure\StoredBranding;
use App\Modules\Integrity\Application\ActivityLog;
use App\Modules\Integrity\Domain\ChangeSet;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;

final readonly class SaveChurchSettings
{
    private const array IDENTITY_FIELDS = [
        'name', 'pastor', 'code', 'rif', 'denomination', 'headquarters', 'registration_note', 'denomination_rif',
    ];

    private const array BRANDING_FIELDS = ['logo_light_sha256', 'logo_dark_sha256', 'favicon_sha256'];

    public function __construct(private ActivityLog $activityLog) {}

    /**
     * @param  array<string, string>  $data
     * @param  array<string, StoredBranding|null>  $branding
     * @return array{settings: ChurchSetting, replaced: list<string>}
     */
    public function __invoke(array $data, array $branding): array
    {
        return DB::transaction(function () use ($data, $branding): array {
            $settings = ChurchSetting::query()->lockForUpdate()->find(1);
            $isNew = $settings === null;
            $settings ??= new ChurchSetting;
            $before = $isNew ? [] : Arr::only($settings->getAttributes(), [...self::IDENTITY_FIELDS, ...self::BRANDING_FIELDS]);
            $replaced = [];

            $settings->forceFill([...$data, 'id' => 1, 'updated_by' => Auth::id()]);

            foreach ($branding as $kind => $asset) {
                if ($asset === null) {
                    continue;
                }

                $pathField = "{$kind}_path";
                $oldPath = $settings->getAttribute($pathField);
                if (is_string($oldPath)) {
                    $replaced[] = $oldPath;
                }

                $settings->forceFill([
                    $pathField => $asset->path,
                    "{$kind}_mime" => $asset->mimeType,
                    "{$kind}_size_bytes" => $asset->sizeBytes,
                    "{$kind}_sha256" => $asset->sha256,
                ]);
            }

            $settings->save();
            $after = Arr::only($settings->getAttributes(), [...self::IDENTITY_FIELDS, ...self::BRANDING_FIELDS]);
            $changes = ChangeSet::between($before, $after);

            foreach ($branding as $kind => $asset) {
                if ($asset !== null) {
                    $changes["{$kind}_file_name"] = ['from' => null, 'to' => $asset->fileName];
                }
            }

            if ($changes !== []) {
                $this->activityLog->record(
                    'church_settings.updated',
                    __('Updated the church settings.'),
                    $settings,
                    $changes,
                );
            }

            return ['settings' => $settings, 'replaced' => $replaced];
        });
    }
}
