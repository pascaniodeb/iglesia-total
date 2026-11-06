<?php

namespace App\Modules\Shared\Spreadsheets;

use DateTimeInterface;
use Illuminate\Support\Str;
use InvalidArgumentException;
use OpenSpout\Reader\XLSX\Reader;

/**
 * Reads the first sheet of an Excel (.xlsx) file with its header row (ported from safipuv-v4's
 * Shared\Imports\Spreadsheet), and turns what a cell holds (text, Excel number or Excel date)
 * into plain text without losing or inventing a cent.
 */
final class SpreadsheetReader
{
    /**
     * The rows of the first sheet keyed by their sheet row number; each column is named after its
     * header, lowercase. Blank rows are skipped.
     *
     * @param  list<string>  $headers  the headers the file must have, in order
     * @return array<int, array<string, mixed>>
     *
     * @throws InvalidArgumentException when the headers are not the template's or there are too many rows
     */
    public static function rows(string $path, array $headers, int $max): array
    {
        $reader = new Reader;
        $reader->open($path);
        $rows = [];

        try {
            foreach ($reader->getSheetIterator() as $sheet) {
                $headings = null;

                foreach ($sheet->getRowIterator() as $index => $row) {
                    $cells = $row->toArray();

                    if ($headings === null) {
                        $headings = array_values(array_filter(
                            array_map(fn (mixed $cell): string => Str::lower(trim(is_scalar($cell) ? (string) $cell : '')), $cells),
                            fn (string $heading): bool => $heading !== '',
                        ));

                        if ($headings !== $headers) {
                            throw new InvalidArgumentException(__('The first row must be the template header: :headers.', ['headers' => implode(', ', $headers)]));
                        }

                        continue;
                    }

                    $values = [];

                    foreach ($headings as $column => $heading) {
                        $values[$heading] = $cells[$column] ?? null;
                    }

                    if (implode('', array_map(self::text(...), $values)) === '') {
                        continue;
                    }

                    $rows[$index] = $values;

                    if (count($rows) > $max) {
                        throw new InvalidArgumentException(__('The file has more than :max rows; split it.', ['max' => $max]));
                    }
                }

                break;
            }
        } finally {
            $reader->close();
        }

        return $rows;
    }

    /**
     * A cell as text. Excel numbers are written back in decimal notation (never "1.0E+5"): ten
     * decimals and trailing zeros removed, which turns the binary 10.050000000000001 back into
     * "10.05" while keeping a real third decimal visible so the caller can refuse it.
     */
    public static function text(mixed $value): string
    {
        return match (true) {
            $value instanceof DateTimeInterface => $value->format('Y-m-d'),
            is_int($value) => (string) $value,
            is_float($value) => rtrim(rtrim(sprintf('%.10F', $value), '0'), '.'),
            is_scalar($value) => trim((string) $value),
            default => '',
        };
    }

    /**
     * An Excel date cell as YYYY-MM-DD; anything else as its text (the caller parses it).
     */
    public static function dateText(mixed $value): string
    {
        return $value instanceof DateTimeInterface ? $value->format('Y-m-d') : self::text($value);
    }
}
