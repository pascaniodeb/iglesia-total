<?php

namespace App\Modules\Accounting\Infrastructure\Supports;

use App\Modules\Accounting\Domain\Journal\SupportInput;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use RuntimeException;

/**
 * Stores the file of a movement's support in private storage (never a public URL).
 *
 * Photos (JPEG, PNG, WebP) are decoded and written again as WebP: that drops every metadata
 * (GPS, camera, author) a phone puts in a picture, and the camera's orientation is applied
 * first so the receipt reads upright. PDFs are kept as they are. The SHA-256 is computed on the
 * bytes actually stored.
 */
final class SupportStorage
{
    public const string DISK = 'local';

    private const array IMAGES = ['image/jpeg', 'image/png', 'image/webp'];

    public function store(UploadedFile $file): SupportInput
    {
        $mime = (string) $file->getMimeType();
        $isImage = in_array($mime, self::IMAGES, true);
        $bytes = $isImage ? self::webp($file) : (string) file_get_contents($file->getRealPath());
        $extension = $isImage ? 'webp' : 'pdf';
        $path = 'supports/'.now()->format('Y/m').'/'.Str::uuid7().'.'.$extension;

        if (! Storage::disk(self::DISK)->put($path, $bytes)) {
            throw new RuntimeException('The support file could not be stored.');
        }

        $name = pathinfo($file->getClientOriginalName(), PATHINFO_FILENAME);

        return new SupportInput(
            path: $path,
            fileName: Str::limit(Str::slug($name) ?: 'soporte', 200, '').'.'.$extension,
            mimeType: $isImage ? 'image/webp' : 'application/pdf',
            sizeBytes: strlen($bytes),
            sha256: hash('sha256', $bytes),
        );
    }

    public function delete(?SupportInput $support): void
    {
        if ($support?->path !== null) {
            Storage::disk(self::DISK)->delete($support->path);
        }
    }

    private static function webp(UploadedFile $file): string
    {
        $raw = (string) file_get_contents($file->getRealPath());
        $image = imagecreatefromstring($raw);

        if ($image === false) {
            throw new RuntimeException('The image could not be read.');
        }

        $exif = $file->getMimeType() === 'image/jpeg' && function_exists('exif_read_data') ? @exif_read_data($file->getRealPath()) : false;
        $orientation = is_array($exif) ? (int) ($exif['Orientation'] ?? 1) : 1;

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
