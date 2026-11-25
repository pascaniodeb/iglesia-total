<?php

namespace App\Modules\Treasury\Application\Import;

use App\Modules\Members\Application\MemberDirectory;
use App\Modules\Shared\Money\Currency;
use App\Modules\Shared\Money\Money;
use App\Modules\Shared\Spreadsheets\SpreadsheetReader;
use App\Modules\Shared\Time\ChurchCalendar;
use App\Modules\Treasury\Domain\Import\ImportedValues;
use InvalidArgumentException;
use Throwable;

/**
 * Reads a tithes workbook (Excel, first sheet, columns fecha, miembro, divisa, monto) and says
 * what it would write, row by row, without writing anything.
 *
 * One currency per row, not three columns: a tithe is written in the notebook as one amount in one
 * currency and is transcribed that way; asking for three columns to fill one and zero the others
 * invites a mistake in the one that matters. The member is required and must be one person of the
 * registry (an ambiguous name is refused, never guessed); a name that is almost right comes back
 * with "did you mean". Dates, amounts and names are read as strictly as in the offerings import.
 */
final readonly class PreviewTitheImport
{
    public const array HEADERS = ['fecha', 'miembro', 'divisa', 'monto'];

    public const int MAX_ROWS = 2000;

    public function __construct(private MemberDirectory $members) {}

    public function __invoke(string $path): TitheImportPreview
    {
        $hash = (string) hash_file('sha256', $path);

        try {
            $sheet = SpreadsheetReader::rows($path, self::HEADERS, self::MAX_ROWS);
        } catch (InvalidArgumentException $exception) {
            return new TitheImportPreview([], [['line' => 1, 'reason' => $exception->getMessage()]], [], self::zeroTotals(), $hash);
        } catch (Throwable) {
            return new TitheImportPreview([], [['line' => 1, 'reason' => __('The file is not an Excel workbook (.xlsx).')]], [], self::zeroTotals(), $hash);
        }

        $people = $this->members->registryNameIndex();
        $names = [];

        foreach ($this->members->registry() as $member) {
            $names[$this->members->normalizeName($member->name)] = $member->name;
        }

        $today = ChurchCalendar::today()->format('Y-m-d');
        $rows = [];
        $rejected = [];

        foreach ($sheet as $line => $cells) {
            $result = $this->row($cells, $line, $people, $names, $today);

            is_string($result) ? $rejected[] = ['line' => $line, 'reason' => $result] : $rows[] = $result;
        }

        return new TitheImportPreview($rows, $rejected, self::duplicates($rows), self::totals($rows), $hash);
    }

    /**
     * @param  array<string, mixed>  $cells  by header
     * @param  array<string, list<string>>  $people  member ids by normalized name
     * @param  array<string, string>  $names  member names by normalized name
     * @return array{line: int, tithe_date: string, member_id: string, member_name: string, amount_ves: string, amount_usd: string, amount_cop: string}|string
     */
    private function row(array $cells, int $line, array $people, array $names, string $today): array|string
    {
        $fecha = SpreadsheetReader::dateText($cells['fecha'] ?? null);
        $miembro = trim(SpreadsheetReader::text($cells['miembro'] ?? null));
        $divisa = SpreadsheetReader::text($cells['divisa'] ?? null);

        $date = preg_match('/^\d{4}-\d{2}-\d{2}$/', $fecha) === 1 ? $fecha : ImportedValues::date($fecha);

        if ($date === null) {
            return __('The date ":value" is not valid (use DD/MM/YYYY).', ['value' => $fecha]);
        }

        if ($date > $today) {
            return __('The date ":value" is in the future.', ['value' => $fecha]);
        }

        if ($miembro === '') {
            return __('A tithe needs its member.');
        }

        $key = $this->members->normalizeName($miembro);
        $ids = $people[$key] ?? [];

        if ($ids === []) {
            $suggestion = self::suggest($key, $names);

            return $suggestion === null
                ? __('The member ":value" is not in the registry.', ['value' => $miembro])
                : __('The member ":value" is not in the registry. Did you mean ":suggestion"?', ['value' => $miembro, 'suggestion' => $suggestion]);
        }

        if (count($ids) > 1) {
            return __('The member ":value" is ambiguous (more than one person has that name).', ['value' => $miembro]);
        }

        $currency = ImportedValues::currency($divisa);

        if ($currency === null) {
            return __('The currency ":value" is not valid (use VES, USD or COP).', ['value' => trim($divisa)]);
        }

        $cell = $cells['monto'] ?? null;
        $raw = SpreadsheetReader::text($cell);
        $amount = is_int($cell) || is_float($cell) ? ImportedValues::amountFromNumber($raw) : ImportedValues::amount($raw);

        if ($amount === null || Money::of($amount, $currency)->isZero()) {
            return __('The amount ":value" is not valid: write an amount greater than zero.', ['value' => $raw]);
        }

        return [
            'line' => $line,
            'tithe_date' => $date,
            'member_id' => $ids[0],
            'member_name' => $miembro,
            'amount_ves' => $currency === Currency::VES ? $amount : '0.00',
            'amount_usd' => $currency === Currency::USD ? $amount : '0.00',
            'amount_cop' => $currency === Currency::COP ? $amount : '0.00',
        ];
    }

    /**
     * The registry name that is nearly the one written, if there is one close enough: someone
     * copying from a notebook drops a letter or writes "GONZALES" for "GONZALEZ". The margin is
     * proportional to the length (two letters is a lot in a short name, a slip in a long one).
     *
     * @param  array<string, string>  $names  member names by normalized name (ASCII)
     */
    private static function suggest(string $key, array $names): ?string
    {
        $limit = max(1, intdiv(strlen($key), 5));
        $best = null;
        $bestDistance = $limit + 1;

        foreach ($names as $candidate => $name) {
            $distance = levenshtein($key, (string) $candidate);

            if ($distance <= $limit && $distance < $bestDistance) {
                $best = $name;
                $bestDistance = $distance;
            }
        }

        return $best;
    }

    /**
     * Lines that repeat an earlier line exactly. Not refused but flagged, so they are confirmed on
     * purpose (a member can tithe the same amount twice in a day).
     *
     * @param  list<array{line: int, tithe_date: string, member_id: string, amount_ves: string, amount_usd: string, amount_cop: string}>  $rows
     * @return list<int>
     */
    private static function duplicates(array $rows): array
    {
        $seen = [];
        $duplicates = [];

        foreach ($rows as $row) {
            $key = implode('|', [$row['tithe_date'], $row['member_id'], $row['amount_ves'], $row['amount_usd'], $row['amount_cop']]);
            isset($seen[$key]) ? $duplicates[] = $row['line'] : $seen[$key] = true;
        }

        return $duplicates;
    }

    /**
     * @param  list<array{amount_ves: string, amount_usd: string, amount_cop: string}>  $rows
     * @return array{ves: string, usd: string, cop: string}
     */
    private static function totals(array $rows): array
    {
        $sum = fn (string $column, Currency $currency): string => Money::sum(
            $currency,
            ...array_map(fn (array $row) => Money::of($row[$column], $currency), $rows),
        )->amount();

        return ['ves' => $sum('amount_ves', Currency::VES), 'usd' => $sum('amount_usd', Currency::USD), 'cop' => $sum('amount_cop', Currency::COP)];
    }

    /**
     * @return array{ves: string, usd: string, cop: string}
     */
    private static function zeroTotals(): array
    {
        return ['ves' => '0.00', 'usd' => '0.00', 'cop' => '0.00'];
    }
}
