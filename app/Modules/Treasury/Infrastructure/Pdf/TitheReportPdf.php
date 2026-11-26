<?php

namespace App\Modules\Treasury\Infrastructure\Pdf;

use App\Modules\Church\Application\ChurchIdentity;
use App\Modules\Church\Application\ChurchLetterhead;
use App\Modules\Church\Application\LetterheadVariant;
use App\Modules\Shared\Pdf\Document;
use App\Modules\Shared\Pdf\Format;
use App\Modules\Shared\Pdf\Table;
use Illuminate\Support\Carbon;
use LogicException;

/**
 * The tithes report of a period on a portrait letter page: the totals per currency, how many
 * members tithed, and each member in alphabetical order (never a ranking) with what they tithed
 * and in how many weeks. Tithes are not income of the church and never touch the books, so this
 * page cites no ledger and no seal: its verification code is the start of the SHA-256 of the
 * figures printed. It is marked confidential: the tithes book is the pastor's.
 */
final class TitheReportPdf
{
    private const float LEFT = 34;

    private const float WIDTH = 544;

    private const float BOTTOM = 735;

    public function __construct(
        private readonly ChurchLetterhead $letterhead,
        private readonly ChurchIdentity $identity,
    ) {}

    /**
     * @param  array{label: string, period: string, totals: array{ves: string, usd: string, cop: string}, members_who_tithed: int, members: list<array{id: string, name: string, ves: string, usd: string, cop: string, weeks: int}>}  $report
     */
    public function render(array $report): string
    {
        $church = $this->identity->profile() ?? throw new LogicException('The church settings have not been filled in.');

        $pdf = new Document('P', 'Letter');
        $pdf->AliasNbPages();
        $pdf->SetTitle(Document::encode('Reporte de diezmos '.$report['period']));
        $pdf->SetAuthor(Document::encode($church->name));
        $pdf->SetSubject(Document::encode($report['label']));
        $pdf->footerLine = 'Documento confidencial · Código de verificación '.$this->code($report).' · Impreso el '.Carbon::now()->format('d/m/Y H:i');
        $pdf->AddPage();

        $y = $this->letterhead->draw($pdf, LetterheadVariant::Stacked, 'REPORTE DE DIEZMOS - '.mb_strtoupper($report['label']));
        $y = $this->summary($pdf, $y, $report, $church->name, $church->pastor);

        $table = new Table($pdf, self::LEFT, [
            ['label' => 'HERMANO', 'width' => 232],
            ['label' => 'BOLÍVARES', 'width' => 80, 'money' => true],
            ['label' => 'PESOS COP', 'width' => 80, 'money' => true],
            ['label' => 'DÓLAR USD', 'width' => 80, 'money' => true],
            ['label' => 'SEMANAS', 'width' => 72, 'align' => 'C'],
        ]);

        $table->draw(
            $y,
            array_map(fn (array $m): array => [$m['name'], Format::cell($m['ves']), Format::cell($m['cop']), Format::cell($m['usd']), (string) $m['weeks']], $report['members']),
            ['TOTAL DEL PERÍODO:', Format::cell($report['totals']['ves']), Format::cell($report['totals']['cop']), Format::cell($report['totals']['usd']), ''],
            function () use ($pdf): float {
                $pdf->AddPage();

                return 40.0;
            },
            self::BOTTOM,
            'NADIE DIEZMÓ EN ESTE PERÍODO',
        );

        return $pdf->Output('S');
    }

    /**
     * @param  array{totals: array{ves: string, usd: string, cop: string}, members_who_tithed: int}  $report
     */
    private function summary(Document $pdf, float $y, array $report, string $church, string $pastor): float
    {
        foreach ([['IGLESIA:', $church, 'PASTOR:', $pastor], ['HERMANOS QUE DIEZMARON:', (string) $report['members_who_tithed'], '', '']] as [$leftLabel, $left, $rightLabel, $right]) {
            $pdf->SetFont('Helvetica', 'B', 7.5);
            $labelWidth = max(60, $pdf->width($leftLabel) + 6);
            $pdf->put(self::LEFT, $y, $labelWidth, 11, $leftLabel);
            $pdf->rule(self::LEFT + $labelWidth, $y + 11, self::LEFT + 262, $y + 11, 0.4, 150);
            $pdf->SetFont('Helvetica', '', 8);
            $pdf->put(self::LEFT + $labelWidth + 3, $y, 262 - $labelWidth - 3, 11, $left);

            if ($rightLabel !== '') {
                $pdf->SetFont('Helvetica', 'B', 7.5);
                $pdf->put(self::LEFT + 282, $y, 50, 11, $rightLabel);
                $pdf->rule(self::LEFT + 334, $y + 11, self::LEFT + self::WIDTH, $y + 11, 0.4, 150);
                $pdf->SetFont('Helvetica', '', 8);
                $pdf->put(self::LEFT + 337, $y, self::WIDTH - 337, 11, $right);
            }

            $y += 16;
        }

        $y += 6;
        $width = (self::WIDTH - 20) / 3;

        foreach ([['TOTAL EN BOLÍVARES', 'ves'], ['TOTAL EN PESOS COP', 'cop'], ['TOTAL EN DÓLARES USD', 'usd']] as $i => [$label, $key]) {
            $x = self::LEFT + $i * ($width + 10);
            $pdf->box($x, $y, $width, 38, 0.75);
            $pdf->SetFont('Helvetica', 'B', 7.5);
            $pdf->put($x, $y + 6, $width, 10, $label, 'C');
            $pdf->SetFont('Helvetica', 'B', 11);
            $pdf->put($x, $y + 19, $width, 14, Format::amount($report['totals'][$key]), 'C');
        }

        return $y + 38 + 18;
    }

    /**
     * @param  array<string, mixed>  $report
     */
    private function code(array $report): string
    {
        $hash = hash('sha256', json_encode([$report['period'], $report['totals'], $report['members']], JSON_THROW_ON_ERROR));

        return strtoupper(implode('-', str_split(substr($hash, 0, 16), 4)));
    }
}
