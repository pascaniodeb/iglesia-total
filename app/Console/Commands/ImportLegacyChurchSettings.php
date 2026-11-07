<?php

namespace App\Console\Commands;

use App\Modules\Church\Application\Actions\SaveChurchSettings;
use App\Modules\Church\Infrastructure\BrandingStorage;
use App\Modules\Church\Infrastructure\Models\ChurchSetting;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;

#[Signature('church:import-legacy-settings
    {--connection=legacy : Database connection of the old system}
    {--legacy-storage= : Legacy storage root; defaults to the sibling TanStack project}
    {--dry-run : Report what would be imported without writing}')]
#[Description('Import the sole church settings row from the old system')]
class ImportLegacyChurchSettings extends Command
{
    /** @var array<string, string> */
    private const array ASSETS = [
        'logo_light' => 'logo_light_url',
        'logo_dark' => 'logo_dark_url',
        'favicon' => 'favicon_url',
    ];

    public function handle(BrandingStorage $storage, SaveChurchSettings $save): int
    {
        $row = DB::connection((string) $this->option('connection'))->table('church_settings')->where('id', 1)->first();

        if ($row === null) {
            $this->components->error('The legacy church settings row (id 1) was not found.');

            return self::FAILURE;
        }

        /** @var array<string, mixed> $legacySettings */
        $legacySettings = get_object_vars($row);
        $missing = [];
        $files = $this->legacyFiles($legacySettings, $missing);
        $this->components->info('Legacy source: church settings row 1.');

        if ($missing !== []) {
            $this->components->warn('Missing legacy branding files: '.implode(', ', $missing));
        }

        if ($this->option('dry-run')) {
            $this->components->warn('Dry run: nothing was written.');

            return self::SUCCESS;
        }

        $stored = [];
        $current = ChurchSetting::query()->find(1);

        try {
            foreach ($files as $kind => $file) {
                $currentHash = $current?->getAttribute("{$kind}_sha256");
                $stored[$kind] = $file === null || $currentHash === $storage->fingerprint($kind, $file)
                    ? null
                    : $storage->store($kind, $file);
            }

            /** @var array<string, string> $data */
            $data = collect([
                'name', 'pastor', 'code', 'rif', 'denomination', 'headquarters', 'registration_note', 'denomination_rif',
            ])->mapWithKeys(fn (string $field): array => [$field => trim((string) $row->{$field})])->all();
            $result = $save($data, $stored);
        } catch (\Throwable $exception) {
            foreach ($stored as $asset) {
                $storage->delete($asset?->path);
            }

            throw $exception;
        }

        foreach ($result['replaced'] as $path) {
            $storage->delete($path);
        }

        $this->components->twoColumnDetail('Church settings', 'imported or refreshed');

        return self::SUCCESS;
    }

    /**
     * @param  array<string, mixed>  $legacySettings
     * @param  list<string>  $missing
     * @return array<string, UploadedFile|null>
     */
    private function legacyFiles(array $legacySettings, array &$missing): array
    {
        $root = (string) ($this->option('legacy-storage') ?: base_path('../../TanStackProjects/iglesiatotal/storage'));
        $files = [];

        foreach (self::ASSETS as $kind => $column) {
            $url = $legacySettings[$column] ?? null;
            if (! is_string($url) || $url === '') {
                $files[$kind] = null;

                continue;
            }

            $filename = basename((string) parse_url($url, PHP_URL_PATH));
            $path = rtrim($root, '/').'/branding/'.$filename;

            if ($filename === '' || ! is_file($path) || ! is_readable($path)) {
                $missing[] = $kind;
                $files[$kind] = null;

                continue;
            }

            $files[$kind] = new UploadedFile($path, $filename, null, null, true);
        }

        return $files;
    }
}
