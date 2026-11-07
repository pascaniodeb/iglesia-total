<?php

namespace App\Modules\Church\Infrastructure;

final readonly class StoredBranding
{
    public function __construct(
        public string $path,
        public string $fileName,
        public string $mimeType,
        public int $sizeBytes,
        public string $sha256,
    ) {}
}
