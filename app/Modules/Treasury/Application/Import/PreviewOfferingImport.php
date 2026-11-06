<?php

namespace App\Modules\Treasury\Application\Import;

use App\Modules\Members\Application\MemberDirectory;
use App\Modules\Shared\Money\Currency;
use App\Modules\Shared\Money\Money;
use App\Modules\Shared\Spreadsheets\SpreadsheetReader;
use App\Modules\Shared\Time\ChurchCalendar;
use App\Modules\Treasury\Domain\Import\ImportedValues;
use App\Modules\Treasury\Domain\OfferingAmounts;
use App\Modules\Treasury\Infrastructure\Models\OfferingCategory;
use InvalidArgumentException;
use Throwable;

/**
 * Reads an offerings workbook (Excel, first sheet, columns fecha, miembro, categoria, concepto,
 * bs, usd, cop) and says what it would write, row by row, without writing anything. Ported from
 * the old system's import, with the same strict readers.
 */
final readonly class PreviewOfferingImport
{
    public const array HEADERS = ['fecha', 'miembro', 'categoria', 'concepto', 'bs', 'usd', 'cop'];

    public const int MAX_ROWS = 2000;

    private const int CONCEPT_MAX = 200;

    public function __construct(private MemberDirectory $members) {}

    public function __invoke(string $path): OfferingImportPreview
    {
        $hash = (string) hash_file('sha256', $path);

        try {
            $sheet = SpreadsheetReader::rows($path, self::HEADERS, self::MAX_ROWS);
        } catch (InvalidArgumentException $exception) {
            return new OfferingImportPreview([], [['line' => 1, 'reason' => $exception->getMessage()]], [], self::zeroTotals(), $hash);
        } catch (Throwable) {
            return new OfferingImportPreview([], [['line' => 1, 'reason' => __('The file is not an Excel workbook (.xlsx).')]], [], self::zeroTotals(), $hash);
        }

        $people = $this->members->registryNameIndex();
        $categories = OfferingCategory::query()->where('is_active', true)->get()->keyBy(fn (OfferingCategory $category) => $this->members->normalizeName($category->name));
        $today = ChurchCalendar::today()->format('Y-m-d');

        $rows = [];
        $rejected = [];

        foreach ($sheet as $line => $cells) {
            $result = $this->row($cells, $line, $people, $categories->all(), $today);

            is_string($result) ? $rejected[] = ['line' => $line, 'reason' => $result] : $rows[] = $result;
        }

        return new OfferingImportPreview($rows, $rejected, self::duplicates($rows), self::totals($rows), $hash);
    }

    /**
     * @param  array<string, mixed>  $cells  by header
     * @param  array<string, list<string>>  $people
     * @param  array<string, OfferingCategory>  $categories
     * @return array{line: int, offering_date: string, member_id: string|null, member_name: string|null, offering_category_id: string, category_name: string, concept: string|null, amount_ves: string, amount_usd: string, amount_cop: string}|string
     */
    private function row(array $cells, int $line, array $people, array $categories, string $today): array|string
    {
        $fecha = SpreadsheetReader::dateText($cells['fecha'] ?? null);
        $miembro = SpreadsheetReader::text($cells['miembro'] ?? null);
        $categoria = SpreadsheetReader::text($cells['categoria'] ?? null);
        $concepto = SpreadsheetReader::text($cells['concepto'] ?? null);

        $date = preg_match('/^\d{4}-\d{2}-\d{2}$/', $fecha) === 1 ? $fecha : ImportedValues::date($fecha);

        if ($date === null) {
            return __('The date ":value" is not valid (use DD/MM/YYYY).', ['value' => $fecha]);
        }

        if ($date > $today) {
            return __('The date ":value" is in the future.', ['value' => $fecha]);
        }

        $memberId = null;
        $memberName = trim($miembro);

        if ($memberName !== '') {
            $ids = $people[$this->members->normalizeName($memberName)] ?? [];

            if ($ids === []) {
                return __('The member ":value" is not in the registry.', ['value' => $memberName]);
            }

            if (count($ids) > 1) {
                return __('The member ":value" is ambiguous (more than one person has that name).', ['value' => $memberName]);
            }

            $memberId = $ids[0];
        }

        $category = $categories[$this->members->normalizeName($categoria)] ?? null;

        if ($category === null) {
            return __('The category ":value" is not an active category.', ['value' => trim($categoria)]);
        }

        $concept = trim($concepto);

        if (mb_strlen($concept) > self::CONCEPT_MAX) {
            return __('The concept is longer than :max characters.', ['max' => self::CONCEPT_MAX]);
        }

        $amounts = [];

        foreach (['VES' => 'bs', 'USD' => 'usd', 'COP' => 'cop'] as $code => $column) {
            $cell = $cells[$column] ?? null;
            $raw = SpreadsheetReader::text($cell);
            $amount = is_int($cell) || is_float($cell) ? ImportedValues::amountFromNumber($raw) : ImportedValues::amount($raw);

            if ($amount === null) {
                return __('The :currency amount ":value" is not valid.', ['currency' => $code, 'value' => $raw]);
            }

            $amounts[$code] = $amount;
        }

        try {
            new OfferingAmounts(Money::of($amounts['VES'], Currency::VES), Money::of($amounts['USD'], Currency::USD), Money::of($amounts['COP'], Currency::COP));
        } catch (InvalidArgumentException) {
            return __('The line has no amount greater than zero.');
        }

        return [
            'line' => $line,
            'offering_date' => $date,
            'member_id' => $memberId,
            'member_name' => $memberId === null ? null : $memberName,
            'offering_category_id' => $category->id,
            'category_name' => $category->name,
            'concept' => $concept === '' ? null : $concept,
            'amount_ves' => $amounts['VES'],
            'amount_usd' => $amounts['USD'],
            'amount_cop' => $amounts['COP'],
        ];
    }

    /**
     * Lines that repeat an earlier line exactly. Not refused (two equal anonymous offerings can
     * be real) but flagged, so they are confirmed on purpose.
     *
     * @param  list<array{line: int, offering_date: string, member_id: string|null, offering_category_id: string, amount_ves: string, amount_usd: string, amount_cop: string}>  $rows
     * @return list<int>
     */
    private static function duplicates(array $rows): array
    {
        $seen = [];
        $duplicates = [];

        foreach ($rows as $row) {
            $key = implode('|', [$row['offering_date'], $row['member_id'] ?? '', $row['offering_category_id'], $row['amount_ves'], $row['amount_usd'], $row['amount_cop']]);
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
