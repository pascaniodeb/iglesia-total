<?php

namespace App\Modules\Church\Application;

final readonly class ChurchBranding
{
    public function __construct(
        public string $path,
        public string $mimeType,
        public int $sizeBytes,
        public string $sha256,
    ) {}
}
