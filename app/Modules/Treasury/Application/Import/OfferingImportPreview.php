<?php

namespace App\Modules\Treasury\Application\Import;

/**
 * What an offerings file would write: the valid rows, every rejected line with its reason, the
 * lines that repeat an earlier line exactly, the totals per currency and the file's SHA-256.
 */
final readonly class OfferingImportPreview
{
    /**
     * @param  list<array{line: int, offering_date: string, member_id: string|null, member_name: string|null, offering_category_id: string, category_name: string, concept: string|null, amount_ves: string, amount_usd: string, amount_cop: string}>  $rows
     * @param  list<array{line: int, reason: string}>  $rejected
     * @param  list<int>  $duplicateLines
     * @param  array{ves: string, usd: string, cop: string}  $totals
     */
    public function __construct(
        public array $rows,
        public array $rejected,
        public array $duplicateLines,
        public array $totals,
        public string $fileHash,
    ) {}

    public function canImport(): bool
    {
        return $this->rows !== [] && $this->rejected === [];
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'rows' => $this->rows,
            'rejected' => $this->rejected,
            'duplicate_lines' => $this->duplicateLines,
            'totals' => $this->totals,
            'file_hash' => $this->fileHash,
            'can_import' => $this->canImport(),
        ];
    }
}
