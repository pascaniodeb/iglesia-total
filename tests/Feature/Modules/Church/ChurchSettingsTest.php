<?php

use App\Enums\UserRole;
use App\Modules\Church\Infrastructure\Models\ChurchSetting;
use App\Modules\Integrity\Infrastructure\Models\ActivityLogEntry;
use Illuminate\Database\QueryException;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Storage;
use Inertia\Testing\AssertableInertia as Assert;

beforeEach(function () {
    Storage::fake('local');
});

/**
 * @param  array<string, mixed>  $overrides
 * @return array<string, mixed>
 */
function churchSettingsPayload(array $overrides = []): array
{
    return [
        'name' => 'Iglesia Cordero Central',
        'pastor' => 'Pastor Pablo Ascanio',
        'code' => 'M07A1985C0001',
        'rif' => 'J-002288007',
        'denomination' => 'Iglesia Pentecostal Unida de Venezuela',
        'headquarters' => 'Sede Nacional Caracas',
        'registration_note' => 'Registro 99564 DG 520 DF 11016',
        'denomination_rif' => 'J-00228900-7',
        ...$overrides,
    ];
}

function transparentLogo(string $name = 'logo.png', int $red = 0): UploadedFile
{
    $image = imagecreatetruecolor(40, 20);
    imagealphablending($image, false);
    imagesavealpha($image, true);
    imagefill($image, 0, 0, imagecolorallocatealpha($image, $red, 0, 0, 127));

    ob_start();
    imagepng($image);

    return UploadedFile::fake()->createWithContent($name, (string) ob_get_clean());
}

function createLegacyChurchSettingsTable(): void
{
    Schema::connection('legacy')->create('church_settings', function (Blueprint $table) {
        $table->smallInteger('id')->primary();
        $table->text('name');
        $table->text('pastor');
        $table->text('code');
        $table->text('rif');
        $table->text('denomination');
        $table->text('headquarters');
        $table->text('registration_note');
        $table->text('denomination_rif');
        $table->timestampTz('updated_at');
        $table->text('logo_light_url')->nullable();
        $table->text('logo_dark_url')->nullable();
        $table->text('favicon_url')->nullable();
    });
}

test('renders the church settings for pastor and secretary only', function (UserRole $role, int $status) {
    signInAs($role);

    $this->get(route('church.settings.edit'))
        ->assertStatus($status)
        ->when($status === 200, fn ($response) => $response->assertInertia(fn (Assert $page) => $page
            ->component('settings/church')
            ->has('settings')));
})->with([
    'pastor' => [UserRole::Pastor, 200],
    'secretary' => [UserRole::Secretary, 200],
    'treasurer' => [UserRole::Treasurer, 403],
    'comptroller' => [UserRole::Comptroller, 403],
]);

test('saves church settings and records changed identity and file fingerprints', function () {
    signInAs(UserRole::Secretary);

    $this->post(route('church.settings.update'), churchSettingsPayload([
        'logo_light' => transparentLogo('marca clara.png'),
        'favicon' => transparentLogo('favicon.png'),
    ]))->assertSessionHasNoErrors();

    $settings = ChurchSetting::query()->sole();
    $this->post(route('church.settings.update'), churchSettingsPayload([
        'name' => 'Iglesia Cordero Central Norte',
        'logo_light' => transparentLogo('nueva marca.png', 80),
    ]))->assertSessionHasNoErrors();

    $entry = ActivityLogEntry::query()->where('action', 'church_settings.updated')->latest('seq')->firstOrFail();

    expect($settings->logo_light_sha256)->not->toBeNull()
        ->and($entry->changes['name'])->toEqual([
            'from' => 'Iglesia Cordero Central',
            'to' => 'Iglesia Cordero Central Norte',
        ])
        ->and($entry->changes['logo_light_sha256']['from'])->toBe($settings->logo_light_sha256)
        ->and($entry->changes['logo_light_sha256']['to'])->toBeString()
        ->and($entry->changes)->not->toHaveKey('logo_light_path');
});

test('the database refuses a second church settings row', function () {
    signInAs(UserRole::Pastor);
    $this->post(route('church.settings.update'), churchSettingsPayload())->assertSessionHasNoErrors();

    expect(fn () => DB::table('church_settings')->insert([
        'id' => 2,
        'name' => 'Otra iglesia',
        'pastor' => 'Otro pastor',
        'code' => 'OTRA',
        'rif' => 'J-000000000',
        'denomination' => 'Otra',
        'headquarters' => 'Otra sede',
        'registration_note' => 'Otro registro',
        'denomination_rif' => 'J-00000000-0',
        'updated_by' => null,
        'updated_at' => now(),
    ]))->toThrow(QueryException::class);
});

test('rejects SVG and unsupported or oversized branding files', function () {
    signInAs(UserRole::Pastor);

    $this->post(route('church.settings.update'), churchSettingsPayload([
        'logo_light' => UploadedFile::fake()->createWithContent('logo.svg', '<svg><script>alert(1)</script></svg>'),
        'favicon' => UploadedFile::fake()->create('favicon.jpg', 10, 'image/jpeg'),
    ]))->assertSessionHasErrors(['logo_light', 'favicon']);

    $this->post(route('church.settings.update'), churchSettingsPayload([
        'logo_dark' => UploadedFile::fake()->create('logo.png', 4097, 'image/png'),
    ]))->assertSessionHasErrors('logo_dark');

    expect(ChurchSetting::query()->count())->toBe(0)
        ->and(Storage::disk('local')->allFiles())->toBe([]);
});

test('serves private branding only after its fingerprint is verified', function () {
    signInAs(UserRole::Pastor);
    $this->post(route('church.settings.update'), churchSettingsPayload(['logo_dark' => transparentLogo('oscuro.png')]))
        ->assertSessionHasNoErrors();

    $settings = ChurchSetting::query()->sole();
    $this->get(route('church.settings.branding', 'logo-dark'))
        ->assertOk()
        ->assertHeader('content-type', 'image/webp');

    Storage::disk('local')->put((string) $settings->logo_dark_path, 'altered');

    $this->get(route('church.settings.branding', 'logo-dark'))->assertStatus(409);

    signInAs(UserRole::Treasurer);
    $this->get(route('church.settings.branding', 'logo-dark'))->assertForbidden();
});

test('imports legacy church settings once and reports missing legacy files', function () {
    createLegacySource();
    createLegacyChurchSettingsTable();
    DB::connection('legacy')->table('church_settings')->insert([
        ...churchSettingsPayload(),
        'id' => 1,
        'updated_at' => '2026-11-07 12:00:00+00',
        'logo_light_url' => '/api/branding/missing-light.png',
        'logo_dark_url' => null,
        'favicon_url' => null,
    ]);

    $this->artisan('church:import-legacy-settings', ['--legacy-storage' => sys_get_temp_dir().'/missing-church-branding'])
        ->expectsOutputToContain('Missing legacy branding files: logo_light')
        ->assertSuccessful();
    $this->artisan('church:import-legacy-settings', ['--legacy-storage' => sys_get_temp_dir().'/missing-church-branding'])
        ->assertSuccessful();

    expect(ChurchSetting::query()->count())->toBe(1)
        ->and(ChurchSetting::query()->sole()->name)->toBe('Iglesia Cordero Central')
        ->and(ChurchSetting::query()->sole()->logo_light_path)->toBeNull();

    dropLegacySource();
});

test('imports a readable legacy logo once and leaves state unchanged on dry run', function () {
    createLegacySource();
    createLegacyChurchSettingsTable();
    $legacyRoot = Storage::disk('local')->path('legacy-church-source');
    $logo = transparentLogo('logo-light.png', 80);
    Storage::disk('local')->put('legacy-church-source/branding/logo-light.png', (string) file_get_contents($logo->getRealPath()));
    DB::connection('legacy')->table('church_settings')->insert([
        ...churchSettingsPayload(),
        'id' => 1,
        'updated_at' => '2026-11-07 12:00:00+00',
        'logo_light_url' => '/api/branding/logo-light.png',
        'logo_dark_url' => null,
        'favicon_url' => null,
    ]);

    $this->artisan('church:import-legacy-settings', ['--legacy-storage' => $legacyRoot])->assertSuccessful();
    $first = ChurchSetting::query()->sole();
    $activityCount = ActivityLogEntry::query()->where('action', 'church_settings.updated')->count();
    $files = Storage::disk('local')->allFiles();

    $this->artisan('church:import-legacy-settings', ['--legacy-storage' => $legacyRoot])->assertSuccessful();
    $this->artisan('church:import-legacy-settings', ['--legacy-storage' => $legacyRoot, '--dry-run' => true])->assertSuccessful();
    $second = ChurchSetting::query()->sole();

    expect($second->logo_light_path)->toBe($first->logo_light_path)
        ->and($second->logo_light_sha256)->toBe($first->logo_light_sha256)
        ->and(ActivityLogEntry::query()->where('action', 'church_settings.updated')->count())->toBe($activityCount)
        ->and(Storage::disk('local')->allFiles())->toBe($files);

    dropLegacySource();
});
