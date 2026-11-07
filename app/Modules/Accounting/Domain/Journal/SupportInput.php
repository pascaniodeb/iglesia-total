<?php

namespace App\Modules\Accounting\Domain\Journal;

use InvalidArgumentException;

/**
 * The support given with a movement: a receipt number, a stored file, or both. The file is
 * already in private storage when this is built (see SupportStorage); this only carries where
 * it is and its fingerprint.
 */
final readonly class SupportInput
{
    public function __construct(
        public ?string $receiptNumber = null,
        public ?string $path = null,
        public ?string $fileName = null,
        public ?string $mimeType = null,
        public ?int $sizeBytes = null,
        public ?string $sha256 = null,
    ) {
        if ($this->receiptNumber === null && $this->path === null) {
            throw new InvalidArgumentException('A support needs a receipt number or a file.');
        }
    }

    public static function from(?string $receiptNumber, ?self $file): ?self
    {
        $receiptNumber = trim((string) $receiptNumber) === '' ? null : trim((string) $receiptNumber);

        if ($receiptNumber === null && $file === null) {
            return null;
        }

        return new self($receiptNumber, $file?->path, $file?->fileName, $file?->mimeType, $file?->sizeBytes, $file?->sha256);
    }

    /**
     * @return array{receipt_number: string|null, file_path: string|null, file_name: string|null, mime_type: string|null, size_bytes: int|null, sha256: string|null}
     */
    public function toColumns(): array
    {
        return [
            'receipt_number' => $this->receiptNumber,
            'file_path' => $this->path,
            'file_name' => $this->fileName,
            'mime_type' => $this->mimeType,
            'size_bytes' => $this->sizeBytes,
            'sha256' => $this->sha256,
        ];
    }
}
