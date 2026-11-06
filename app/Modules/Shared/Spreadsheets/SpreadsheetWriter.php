<?php

namespace App\Modules\Shared\Spreadsheets;

use OpenSpout\Common\Entity\Row;
use OpenSpout\Common\Entity\Style\Style;
use OpenSpout\Writer\XLSX\Writer;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Excel (.xlsx) written row by row straight to the response (OpenSpout, ported from safipuv-v4's
 * ReportSpreadsheet): constant memory however many rows. Each sheet has a bold header.
 */
final class SpreadsheetWriter
{
    /**
     * @param  array<string, array{headings: list<string>, rows: iterable<list<string|int|float|null>>}>  $sheets  by sheet name, in order
     */
    public static function download(string $filename, array $sheets): StreamedResponse
    {
        return new StreamedResponse(function () use ($sheets): void {
            $writer = new Writer;
            $writer->openToFile('php://output');
            $header = (new Style)->withFontBold(true)->withFontColor('FFFFFF')->withBackgroundColor('1F2937');
            $first = true;

            foreach ($sheets as $name => $sheet) {
                if (! $first) {
                    $writer->addNewSheetAndMakeItCurrent();
                }

                $writer->getCurrentSheet()->setName($name);
                $writer->addRow(Row::fromValuesWithStyle($sheet['headings'], $header));

                foreach ($sheet['rows'] as $row) {
                    $writer->addRow(Row::fromValues($row));
                }

                $first = false;
            }

            $writer->close();
        }, 200, [
            'Content-Type' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
            'Content-Disposition' => 'attachment; filename="'.$filename.'"',
            'Cache-Control' => 'no-store',
        ]);
    }
}
