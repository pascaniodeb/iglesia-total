<?php

namespace App\Modules\Treasury\Infrastructure\Pdf;

use App\Modules\Accounting\Application\ChartOfAccounts;
use App\Modules\Accounting\Domain\AccountRef;
use App\Modules\Church\Application\ChurchIdentity;
use App\Modules\Church\Application\ChurchLetterhead;
use App\Modules\Church\Application\LetterheadVariant;
use App\Modules\Integrity\Application\SealedPeriods;
use App\Modules\Integrity\Domain\Ledger\Canonical;
use App\Modules\Shared\Pdf\Document;
use App\Modules\Shared\Pdf\Format;
use App\Modules\Shared\Pdf\Table;
use App\Modules\Shared\Time\IsoWeek;
use App\Modules\Shared\Time\Month;
use App\Modules\Treasury\Infrastructure\Models\MonthlyOfferingClosure;
use App\Modules\Treasury\Infrastructure\Models\WeeklyClosure;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use LogicException;

/**
 * The monthly offering closure on a portrait letter page: the weeks that touch the month (with the
 * totals each one closed with) and the month's offerings by category with the account code each
 * one was posted to. The totals are the closure's own, which PostgreSQL checked against the
 * offerings when the month was closed. It cites the sealed block of the accounting month when
 * there is one (the monthly seal is what binds this closure).
 */
final class MonthlyOfferingClosurePdf
{
    private const float LEFT = 34;

    private const float WIDTH = 544;

    private const float BOTTOM = 735;

    public function __construct(
        private readonly ChurchLetterhead $letterhead,
        private readonly ChurchIdentity $identity,
        private readonly ChartOfAccounts $chart,
        private readonly SealedPeriods $sealed,
    ) {}

    public function render(MonthlyOfferingClosure $closure): string
    {
        $church = $this->identity->profile() ?? throw new LogicException('The church settings have not been filled in.');
        $closure->loadMissing('lines');
        $month = Month::fromKey($closure->month);
        $label = mb_strtoupper(Format::monthName($month));

        $pdf = new Document('P', 'Letter');
        $pdf->AliasNbPages();
        $pdf->SetTitle(Document::encode("Cierre de ofrendas {$closure->month}"));
        $pdf->SetAuthor(Document::encode($church->name));
        $pdf->SetSubject(Document::encode($label));
        $accounts = $this->chart->findMany(array_values(array_unique($closure->lines->pluck('account_id')->all())));
        $categories = $this->categoryRows($closure, $accounts);
        $weeks = $this->weekRows($month);
        $pdf->footerLine = $this->footerLine($closure, $categories, $weeks);
        $pdf->AddPage();

        $newPage = function () use ($pdf): float {
            $pdf->AddPage();

            return 40.0;
        };

        $y = $this->letterhead->draw($pdf, LetterheadVariant::Stacked, 'CIERRE MENSUAL DE OFRENDAS - '.$label);
        $pdf->SetFont('Helvetica', 'B', 7.5);
        $pdf->put(self::LEFT, $y, 42, 11, 'IGLESIA:');
        $pdf->rule(self::LEFT + 44, $y + 11, self::LEFT + 262, $y + 11, 0.4, 150);
        $pdf->put(self::LEFT + 282, $y, 44, 11, 'PASTOR:');
        $pdf->rule(self::LEFT + 328, $y + 11, self::LEFT + self::WIDTH, $y + 11, 0.4, 150);
        $pdf->SetFont('Helvetica', '', 8);
        $pdf->put(self::LEFT + 47, $y, 213, 11, $church->name);
        $pdf->put(self::LEFT + 331, $y, self::WIDTH - 331, 11, $church->pastor);
        $y += 20;

        $pdf->SetFont('Helvetica', 'B', 9);
        $pdf->put(self::LEFT, $y, self::WIDTH, 12, 'SEMANAS DEL MES');
        $pdf->SetFont('Helvetica', '', 6.5);
        $pdf->put(self::LEFT, $y + 12, self::WIDTH, 9, 'Una semana puede traer días de otro mes: cada semana se cierra entera, el mes se compone de las ofrendas fechadas en él.');
        $y += 25;
        $y = (new Table($pdf, self::LEFT, [
            ['label' => 'SEMANA', 'width' => 64, 'align' => 'C'],
            ['label' => 'DEL / AL', 'width' => 130, 'align' => 'C'],
            ['label' => 'OFRENDAS', 'width' => 50, 'align' => 'C'],
            ['label' => 'BOLÍVARES', 'width' => 100, 'money' => true],
            ['label' => 'PESOS COP', 'width' => 100, 'money' => true],
            ['label' => 'DÓLAR USD', 'width' => 100, 'money' => true],
        ]))->draw($y, $weeks, null, $newPage, self::BOTTOM, 'NINGUNA SEMANA CERRADA');

        $y += 22;
        $pdf->SetFont('Helvetica', 'B', 9);
        $pdf->put(self::LEFT, $y, self::WIDTH, 12, 'OFRENDAS DEL MES POR CATEGORÍA');
        $y += 16;
        $y = (new Table($pdf, self::LEFT, [
            ['label' => 'CÓDIGO', 'width' => 52, 'align' => 'C'],
            ['label' => 'CATEGORÍA', 'width' => 172],
            ['label' => 'OFRENDAS', 'width' => 50, 'align' => 'C'],
            ['label' => 'BOLÍVARES', 'width' => 90, 'money' => true],
            ['label' => 'PESOS COP', 'width' => 90, 'money' => true],
            ['label' => 'DÓLAR USD', 'width' => 90, 'money' => true],
        ]))->draw($y, $categories, [
            '', 'TOTAL DEL MES:', (string) $closure->offerings_count,
            Format::cell((string) $closure->total_ves), Format::cell((string) $closure->total_cop), Format::cell((string) $closure->total_usd),
        ], $newPage, self::BOTTOM);

        $pdf->SetFont('Helvetica', '', 7);
        $pdf->put(self::LEFT, $y + 10, self::WIDTH, 10, 'Cerrado el '.Format::date($closure->closed_at->toDateString()).($closure->journal_entry_id === null ? '' : ' y contabilizado en el Libro Diario.'));

        return $pdf->Output('S');
    }

    /**
     * @param  array<string, AccountRef>  $accounts
     * @return list<list<string>>
     */
    private function categoryRows(MonthlyOfferingClosure $closure, array $accounts): array
    {
        $names = DB::table('offering_categories')->whereIn('id', $closure->lines->pluck('offering_category_id')->all())->pluck('name', 'id');
        $rows = [];

        foreach ($closure->lines as $line) {
            $rows[] = [
                isset($accounts[$line->account_id]) ? $accounts[$line->account_id]->code : '',
                (string) ($names[$line->offering_category_id] ?? ''),
                (string) $line->offerings_count,
                Format::cell((string) $line->total_ves),
                Format::cell((string) $line->total_cop),
                Format::cell((string) $line->total_usd),
            ];
        }

        usort($rows, fn (array $a, array $b): int => [$a[0], $a[1]] <=> [$b[0], $b[1]]);

        return $rows;
    }

    /**
     * @return list<list<string>>
     */
    private function weekRows(Month $month): array
    {
        $keys = array_map(fn (IsoWeek $week): string => $week->key(), $month->weeks());
        $rows = [];

        foreach (WeeklyClosure::query()->whereIn('week_key', $keys)->orderBy('week_start')->get() as $week) {
            $rows[] = [
                $week->week_key,
                Format::date($week->week_start->toDateString()).' al '.Format::date($week->week_end->toDateString()),
                (string) $week->offerings_count,
                Format::cell((string) $week->total_ves),
                Format::cell((string) $week->total_cop),
                Format::cell((string) $week->total_usd),
            ];
        }

        return $rows;
    }

    /**
     * @param  list<list<string>>  $categories
     * @param  list<list<string>>  $weeks
     */
    private function footerLine(MonthlyOfferingClosure $closure, array $categories, array $weeks): string
    {
        $code = strtoupper(implode('-', str_split(substr(Canonical::hash([
            'month' => $closure->month,
            'count' => $closure->offerings_count,
            'totals' => [(string) $closure->total_ves, (string) $closure->total_usd, (string) $closure->total_cop],
            'categories' => $categories,
            'weeks' => $weeks,
        ]), 0, 16), 4)));
        $seal = $this->sealed->of('monthly', [$closure->month])[$closure->month] ?? null;

        return "Código de verificación {$code} · ".($seal === null ? 'Mes contable aún sin sellar' : 'Mes contable sellado en el bloque '.$seal['height'].' '.substr($seal['hash'], 0, 16)).' · Impreso el '.Carbon::now()->format('d/m/Y H:i');
    }
}
