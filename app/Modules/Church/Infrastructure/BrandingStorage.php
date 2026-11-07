<?php

namespace App\Modules\Church\Infrastructure;

use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use RuntimeException;

/** Stores normalized church branding on the private local disk. */
final class BrandingStorage
{
    public const string DISK = 'local';

    /** @var array<string, string> */
    private const array PREFIXES = [
        'logo_light' => 'logo-light',
        'logo_dark' => 'logo-dark',
        'favicon' => 'favicon',
    ];

    public function store(string $kind, UploadedFile $file): StoredBranding
    {
        $mimeType = (string) $file->getMimeType();
        $isIco = $kind === 'favicon' && $mimeType === 'image/x-icon';
        $bytes = $this->bytes($kind, $file);
        $extension = $isIco ? 'ico' : 'webp';
        $path = 'church-branding/'.now()->format('Y/m').'/'.Str::uuid7().'.'.$extension;

        if (! Storage::disk(self::DISK)->put($path, $bytes)) {
            throw new RuntimeException('The branding file could not be stored.');
        }

        return new StoredBranding(
            path: $path,
            fileName: Str::limit(Str::slug(pathinfo($file->getClientOriginalName(), PATHINFO_FILENAME)) ?: self::PREFIXES[$kind], 200, '').'.'.$extension,
            mimeType: $isIco ? 'image/x-icon' : 'image/webp',
            sizeBytes: strlen($bytes),
            sha256: hash('sha256', $bytes),
        );
    }

    public function fingerprint(string $kind, UploadedFile $file): string
    {
        return hash('sha256', $this->bytes($kind, $file));
    }

    public function delete(?string $path): void
    {
        if ($path !== null) {
            Storage::disk(self::DISK)->delete($path);
        }
    }

    private function bytes(string $kind, UploadedFile $file): string
    {
        return $kind === 'favicon' && $file->getMimeType() === 'image/x-icon'
            ? $this->raw($file)
            : $this->webp($file);
    }

    private function raw(UploadedFile $file): string
    {
        $bytes = file_get_contents($file->getRealPath());

        if (! is_string($bytes)) {
            throw new RuntimeException('The branding file could not be read.');
        }

        return $bytes;
    }

    private function webp(UploadedFile $file): string
    {
        $image = imagecreatefromstring($this->raw($file));

        if ($image === false) {
            throw new RuntimeException('The branding image could not be read.');
        }

        $orientation = $file->getMimeType() === 'image/jpeg' && function_exists('exif_read_data')
            ? (int) ((@exif_read_data($file->getRealPath()) ?: [])['Orientation'] ?? 1)
            : 1;
        $rotated = match ($orientation) {
            3 => imagerotate($image, 180, 0),
            6 => imagerotate($image, -90, 0),
            8 => imagerotate($image, 90, 0),
            default => $image,
        };

        ob_start();
        imagewebp($rotated === false ? $image : $rotated, null, 85);

        return (string) ob_get_clean();
    }
}
