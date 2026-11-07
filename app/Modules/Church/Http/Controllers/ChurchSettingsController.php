<?php

namespace App\Modules\Church\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Church\Application\Actions\SaveChurchSettings;
use App\Modules\Church\Http\Requests\ChurchSettingsRequest;
use App\Modules\Church\Http\Resources\ChurchSettingsResource;
use App\Modules\Church\Infrastructure\BrandingStorage;
use App\Modules\Church\Infrastructure\Models\ChurchSetting;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Inertia\Inertia;
use Inertia\Response;
use Symfony\Component\HttpFoundation\StreamedResponse;
use Throwable;

class ChurchSettingsController extends Controller
{
    /** @var list<string> */
    private const array IDENTITY_FIELDS = [
        'name', 'pastor', 'code', 'rif', 'denomination', 'headquarters', 'registration_note', 'denomination_rif',
    ];

    /** @var array<string, string> */
    private const array BRANDING = [
        'logo-light' => 'logo_light',
        'logo-dark' => 'logo_dark',
        'favicon' => 'favicon',
    ];

    public function edit(): Response
    {
        $settings = ChurchSetting::query()->find(1);

        return Inertia::render('settings/church', [
            'settings' => $settings === null ? null : (new ChurchSettingsResource($settings))->resolve(),
        ]);
    }

    public function update(ChurchSettingsRequest $request, BrandingStorage $storage, SaveChurchSettings $save): RedirectResponse
    {
        $stored = [];

        try {
            foreach (self::BRANDING as $kind) {
                $file = $request->file($kind);
                $stored[$kind] = $file instanceof UploadedFile ? $storage->store($kind, $file) : null;
            }

            /** @var array<string, string> $data */
            $data = $request->safe()->only(self::IDENTITY_FIELDS);
            $result = $save($data, $stored);
        } catch (Throwable $exception) {
            foreach ($stored as $asset) {
                $storage->delete($asset?->path);
            }

            throw $exception;
        }

        foreach ($result['replaced'] as $path) {
            $storage->delete($path);
        }

        Inertia::flash('toast', ['type' => 'success', 'message' => __('Church settings updated.')]);

        return back();
    }

    public function branding(string $kind): StreamedResponse
    {
        $prefix = self::BRANDING[$kind] ?? abort(404);
        $settings = ChurchSetting::query()->find(1);
        $path = $settings?->getAttribute("{$prefix}_path");

        abort_if(! is_string($path) || ! Storage::disk(BrandingStorage::DISK)->exists($path), 404);

        $bytes = (string) Storage::disk(BrandingStorage::DISK)->get($path);
        abort_unless(hash_equals((string) $settings->getAttribute("{$prefix}_sha256"), hash('sha256', $bytes)), 409, __('The branding file was altered after it was uploaded.'));

        return response()->streamDownload(fn () => print ($bytes), basename($path), [
            'Content-Type' => (string) $settings->getAttribute("{$prefix}_mime"),
            'Cache-Control' => 'private, no-store',
        ], 'inline');
    }
}
