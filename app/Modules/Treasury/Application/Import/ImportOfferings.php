<?php

namespace App\Modules\Treasury\Application\Import;

use App\Modules\Integrity\Application\ActivityLog;
use App\Modules\Treasury\Infrastructure\Models\Offering;
use Illuminate\Contracts\Auth\Factory as AuthFactory;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Writes an offerings workbook, all or nothing: if any line is rejected nothing is written (a
 * half-loaded sheet is how money goes missing without anyone noticing). The file is read
 * again here; the preview the browser saw is never trusted. Exact duplicates must be confirmed.
 * The import is recorded in the activity log with its totals and the file's SHA-256.
 */
final readonly class ImportOfferings
{
    public function __construct(
        private PreviewOfferingImport $preview,
        private ActivityLog $activityLog,
        private AuthFactory $auth,
    ) {}

    /**
     * @throws ValidationException
     */
    public function __invoke(string $path, bool $confirmDuplicates): int
    {
        $preview = ($this->preview)($path);

        if ($preview->rejected !== []) {
            throw ValidationException::withMessages(['file' => __('The file has :count lines with errors: fix them and try again. Nothing was imported.', ['count' => count($preview->rejected)])]);
        }

        if ($preview->rows === []) {
            throw ValidationException::withMessages(['file' => __('The file has no offerings to import.')]);
        }

        if ($preview->duplicateLines !== [] && ! $confirmDuplicates) {
            throw ValidationException::withMessages(['file' => __('The file repeats lines exactly; confirm that they are separate offerings.')]);
        }

        $actorId = $this->auth->guard()->id();

        return DB::transaction(function () use ($preview, $actorId): int {
            foreach ($preview->rows as $row) {
                $offering = new Offering([
                    'offering_date' => $row['offering_date'],
                    'member_id' => $row['member_id'],
                    'offering_category_id' => $row['offering_category_id'],
                    'concept' => $row['concept'],
                    'amount_ves' => $row['amount_ves'],
                    'amount_usd' => $row['amount_usd'],
                    'amount_cop' => $row['amount_cop'],
                ]);
                $offering->created_by = $actorId === null ? null : (string) $actorId;
                $offering->save();
            }

            $this->activityLog->record(
                'offerings.imported',
                __('Imported :count offerings from a file.', ['count' => count($preview->rows)]),
                changes: [
                    'count' => count($preview->rows),
                    'totals' => $preview->totals,
                    'duplicates' => count($preview->duplicateLines),
                    'file_sha256' => $preview->fileHash,
                ],
            );

            return count($preview->rows);
        });
    }
}
