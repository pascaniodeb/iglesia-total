<?php

namespace App\Modules\Accounting\Infrastructure\Pdf;

use App\Modules\Church\Application\ChurchIdentity;
use App\Modules\Church\Application\ChurchLetterhead;
use App\Modules\Church\Application\LetterheadVariant;
use App\Modules\Integrity\Application\SealedPeriods;
use App\Modules\Integrity\Domain\Ledger\Canonical;
use App\Modules\Shared\Money\Currency;
use App\Modules\Shared\Money\Money;
use App\Modules\Shared\Pdf\Document;
use App\Modules\Shared\Pdf\Format;
use App\Modules\Shared\Pdf\Table;
use Illuminate\Support\Carbon;
use LogicException;

/**
 * The accounting report of a period on a portrait letter page: income and expense by account code,
 * the summary (opening, income, expense, closing; the capital and the currency exchanges only when
 * there are any) and the three closing balances. Currencies are columns, never added together.
 *
 * It is drawn again on every request from the same figures as the screen. While a month of the
 * period is open the page says BORRADOR (a watermark and a line), because its figures can still
 * change. Every page carries a verification code (the start of the SHA-256 of the figures printed)
 * and the head of the sealed ledger at the moment of printing.
 */
final class PeriodReportPdf
{
    private const float LEFT = 34;

    private const float WIDTH = 544;

    private const float BOTTOM = 735;

    /** Currency columns in the order of the paper form: bolívares, pesos, dólares. */
    private const array COLUMNS = ['ves' => 'BOLÍVARES', 'cop' => 'PESOS COP', 'usd' => 'DÓLAR USD'];

    public function __construct(
        private readonly ChurchLetterhead $letterhead,
        private readonly ChurchIdentity $identity,
        private readonly SealedPeriods $sealed,
    ) {}

    /**
     * @param  array<string, mixed>  $report  the shape PeriodReport::of() returns
     */
    public function render(array $report): string
    {
        $church = $this->identity->profile() ?? throw new LogicException('The church settings have not been filled in.');
        $label = (string) $report['label'];
        $isDraft = (bool) $report['is_draft'];

        $pdf = new Document('P', 'Letter');
        $pdf->AliasNbPages();
        $pdf->SetTitle(Document::encode('Reporte de contabilidad '.$report['period']));
        $pdf->SetAuthor(Document::encode($church->name));
        $pdf->SetSubject(Document::encode($label));
        $pdf->footerLine = $this->footerLine($report);
        $pdf->AddPage();

        $newPage = function () use ($pdf, $isDraft): float {
            $pdf->AddPage();
            $isDraft && $pdf->watermark('BORRADOR');

            return 40.0;
        };
        $isDraft && $pdf->watermark('BORRADOR');

        $y = $this->letterhead->draw($pdf, LetterheadVariant::Stacked, 'REPORTE DE CONTABILIDAD - '.mb_strtoupper($label));
        $y = $this->identityBlock($pdf, $y, $report);

        /** @var list<array{code: string, name: string, type: string, entries: int, ves: string, usd: string, cop: string}> $codes */
        $codes = $report['by_code'];
        $y = $this->section($pdf, $y, 'INGRESOS DEL PERÍODO');
        $y = $this->codes($pdf, $y, array_values(array_filter($codes, fn (array $row): bool => $row['type'] === 'income')), 'DESCRIPCIÓN DE INGRESOS', 'TOTAL DE INGRESOS:', $newPage);
        $y = $this->section($pdf, $y + 4, 'EGRESOS DEL PERÍODO');
        $y = $this->codes($pdf, $y, array_values(array_filter($codes, fn (array $row): bool => $row['type'] === 'expense')), 'DESCRIPCIÓN DE EGRESOS', 'TOTAL DE EGRESOS:', $newPage);

        /** @var array<string, array<string, string>> $currencies */
        $currencies = $report['currencies'];

        if ($y > self::BOTTOM - 150) {
            $y = $newPage();
        }

        $y = $this->section($pdf, $y + 4, 'RESUMEN DEL PERÍODO');
        $y = $this->summary($pdf, $y, $currencies, $newPage);
        $this->balances($pdf, $y + 16, $currencies);

        return $pdf->Output('S');
    }

    /**
     * @param  array<string, mixed>  $report
     */
    private function identityBlock(Document $pdf, float $y, array $report): float
    {
        $church = $this->identity->profile() ?? throw new LogicException('The church settings have not been filled in.');
        /** @var list<array{month: string, is_closed: bool}> $months */
        $months = $report['months'];
        $closed = array_values(array_map(fn (array $m): string => $m['month'], array_filter($months, fn (array $m): bool => $m['is_closed'])));
        $range = $closed === [] ? '—' : (count($closed) === 1 ? $closed[0] : $closed[0].' a '.$closed[count($closed) - 1]);

        $rows = [
            [['IGLESIA:', $church->name, 60], ['PASTOR:', $church->pastor, 60]],
            [['RIF:', $church->rif, 60], ['Código de Iglesia:', $church->code, 90]],
            [['Meses cerrados:', $range, 78], ['Meses incluidos:', (string) count($closed), 84]],
        ];

        foreach ($rows as $row) {
            foreach ($row as $index => [$label, $value, $labelWidth]) {
                $x = self::LEFT + $index * (self::WIDTH / 2 + 8);
                $width = self::WIDTH / 2 - 8;
                $pdf->SetFont('Helvetica', 'B', 7.5);
                $pdf->put($x, $y, $labelWidth, 11, $label);
                $pdf->rule($x + $labelWidth, $y + 11, $x + $width, $y + 11, 0.4, 150);
                $pdf->SetFont('Helvetica', '', 8);
                $pdf->put($x + $labelWidth + 3, $y, $width - $labelWidth - 3, 11, $value);
            }
            $y += 15;
        }

        if ($report['is_draft']) {
            $open = array_values(array_map(fn (array $m): string => $m['month'], array_filter($months, fn (array $m): bool => ! $m['is_closed'])));
            $pdf->SetFont('Helvetica', 'B', 7.5);
            $pdf->SetTextColor(179, 38, 30);
            $lines = $pdf->wrap(self::LEFT, $y + 2, self::WIDTH, 9, 'BORRADOR: '.implode(', ', $open).(count($open) === 1 ? ' no está cerrado' : ' no están cerrados').' y sus cifras pueden cambiar.', 2);
            $pdf->SetTextColor(0);
            $y += $lines * 9 + 4;
        }

        return $y;
    }

    private function section(Document $pdf, float $y, string $title): float
    {
        $pdf->SetFont('Helvetica', 'B', 9);
        $pdf->put(self::LEFT, $y + 6, self::WIDTH, 12, $title);

        return $y + 22;
    }

    /**
     * @param  list<array{code: string, name: string, type: string, entries: int, ves: string, usd: string, cop: string}>  $rows
     * @param  callable(): float  $newPage
     */
    private function codes(Document $pdf, float $y, array $rows, string $heading, string $totalLabel, callable $newPage): float
    {
        $table = new Table($pdf, self::LEFT, [
            ['label' => 'CÓDIGO', 'width' => 52, 'align' => 'C'],
            ['label' => $heading, 'width' => 252],
            ...array_map(fn (string $label): array => ['label' => $label, 'width' => 80, 'money' => true], array_values(self::COLUMNS)),
        ]);

        $total = ['', $totalLabel];

        foreach (array_keys(self::COLUMNS) as $key) {
            $total[] = Format::cell($this->sum($key, array_column($rows, $key)));
        }

        return $table->draw(
            $y,
            array_map(fn (array $row): array => [$row['code'], $row['name'], ...array_map(fn (string $key): string => Format::cell($row[$key]), array_keys(self::COLUMNS))], $rows),
            $total,
            $newPage,
            self::BOTTOM,
        );
    }

    /**
     * @param  array<string, array<string, string>>  $currencies  by VES, USD, COP
     * @param  callable(): float  $newPage
     */
    private function summary(Document $pdf, float $y, array $currencies, callable $newPage): float
    {
        $table = new Table($pdf, self::LEFT, [
            ['label' => 'CONCEPTO', 'width' => 292, 'align' => 'R'],
            ...array_map(fn (string $label): array => ['label' => $label, 'width' => 84, 'money' => true], array_values(self::COLUMNS)),
        ]);

        $line = function (string $label, string $figure) use ($currencies): array {
            return [$label, ...array_map(fn (string $key): string => Format::cell($currencies[strtoupper($key)][$figure] ?? '0'), array_keys(self::COLUMNS))];
        };
        $hasAny = fn (string $figure): bool => array_filter(array_map(fn (string $c): string => $currencies[$c][$figure] ?? '0', ['VES', 'USD', 'COP']), fn (string $v): bool => Format::cell($v) !== '-') !== [];

        $rows = [$line('SALDO ANTERIOR:', 'opening'), $line('(+) INGRESOS DEL PERÍODO:', 'income'), $line('(-) EGRESOS DEL PERÍODO:', 'expense')];

        if ($hasAny('capital')) {
            $rows[] = $line('(+) CAPITAL INICIAL:', 'capital');
        }

        if ($hasAny('exchange')) {
            $rows[] = $line('(+/-) CAMBIOS DE DIVISA:', 'exchange');
        }

        return $table->draw($y, $rows, $line('= SALDO AL CIERRE:', 'closing'), $newPage, self::BOTTOM);
    }

    /**
     * @param  array<string, array<string, string>>  $currencies
     */
    private function balances(Document $pdf, float $y, array $currencies): void
    {
        $width = (self::WIDTH - 20) / 3;

        foreach ([['SALDO EN BOLÍVARES', 'VES'], ['SALDO EN PESOS COP', 'COP'], ['SALDO EN DÓLARES USD', 'USD']] as $index => [$label, $currency]) {
            $x = self::LEFT + $index * ($width + 10);
            $value = $currencies[$currency]['closing'] ?? '0';
            $pdf->box($x, $y, $width, 40, 0.75);
            $pdf->SetFont('Helvetica', 'B', 7.5);
            $pdf->put($x, $y + 7, $width, 10, $label, 'C');
            $pdf->SetFont('Helvetica', 'B', 11);
            str_starts_with($value, '-') ? $pdf->SetTextColor(179, 38, 30) : $pdf->SetTextColor(17);
            $pdf->put($x, $y + 21, $width, 14, Format::amount($value), 'C');
            $pdf->SetTextColor(0);
        }
    }

    /**
     * @param  array<string, mixed>  $report
     */
    private function footerLine(array $report): string
    {
        $code = strtoupper(implode('-', str_split(substr(Canonical::hash([
            'period' => $report['period'],
            'months' => $report['months'],
            'currencies' => $report['currencies'],
            'by_code' => $report['by_code'],
        ]), 0, 16), 4)));
        $head = $this->sealed->head();

        return "Código de verificación {$code}".($head === null ? '' : ' · Cadena sellada: bloque '.$head['height'].' '.substr($head['hash'], 0, 16)).' · Impreso el '.Carbon::now()->format('d/m/Y H:i');
    }

    /**
     * @param  list<string>  $values
     */
    private function sum(string $key, array $values): string
    {
        $currency = Currency::from(strtoupper($key));

        return Money::sum($currency, ...array_map(fn (string $value): Money => Money::of($value, $currency), $values))->amount();
    }
}
