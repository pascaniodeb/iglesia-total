<?php

namespace App\Modules\Integrity\Infrastructure\Pdf;

use App\Modules\Church\Application\ChurchIdentity;
use App\Modules\Church\Application\ChurchLetterhead;
use App\Modules\Church\Application\LetterheadVariant;
use App\Modules\Integrity\Application\SealedPeriods;
use App\Modules\Integrity\Domain\Ledger\Canonical;
use App\Modules\Integrity\Infrastructure\Models\Audit;
use App\Modules\Shared\Pdf\Document;
use Illuminate\Support\Carbon;
use LogicException;

/**
 * The report of one audit, drawn from what was recorded when it ran (never from the live books, so
 * it says what the auditor saw): the result, the notes, each check with what it found and, for each
 * finding, whether it was accepted and why. An audit imported from the old system keeps its figures
 * and notes; it has no detail per finding to print.
 *
 * Every page carries the verification code (the start of the SHA-256 of the stored snapshot) and the
 * head of the sealed ledger at the moment of printing.
 */
final class AuditReportPdf
{
    private const float LEFT = 34;

    private const float WIDTH = 544;

    private const float BOTTOM = 735;

    private const array SEVERITY = ['critical' => 'CRÍTICO', 'warning' => 'ADVERTENCIA', 'info' => 'INFORMATIVO'];

    public function __construct(
        private readonly ChurchLetterhead $letterhead,
        private readonly ChurchIdentity $identity,
        private readonly SealedPeriods $sealed,
    ) {}

    public function render(Audit $audit): string
    {
        $church = $this->identity->profile() ?? throw new LogicException('The church settings have not been filled in.');
        $audit->loadMissing('runner');
        $clean = $audit->status === 'clean';

        $pdf = new Document('P', 'Letter');
        $pdf->AliasNbPages();
        $pdf->SetTitle(Document::encode('Reporte de auditoría '.$audit->ran_at->format('d/m/Y')));
        $pdf->SetAuthor(Document::encode($church->name));
        $pdf->footerLine = $this->footerLine($audit);
        $pdf->AddPage();

        $y = $this->letterhead->draw($pdf, LetterheadVariant::Stacked, 'REPORTE DE AUDITORÍA');

        foreach ([
            [['IGLESIA:', $church->name], ['PASTOR:', $church->pastor]],
            [['EJECUTADA EL:', $audit->ran_at->format('d/m/Y H:i')], ['POR:', (string) $audit->runner?->name]],
        ] as $row) {
            foreach ($row as $i => [$label, $value]) {
                $x = self::LEFT + $i * 280;
                $pdf->SetFont('Helvetica', 'B', 7.5);
                $pdf->put($x, $y, 72, 11, $label);
                $pdf->rule($x + 74, $y + 11, $x + 262, $y + 11, 0.4, 150);
                $pdf->SetFont('Helvetica', '', 8.5);
                $pdf->put($x + 77, $y, 185, 11, $value);
            }
            $y += 16;
        }

        $y += 6;
        $pdf->box(self::LEFT, $y, self::WIDTH, 40, 1.25, $clean ? 40 : 120);
        $clean ? $pdf->SetTextColor(22, 101, 52) : $pdf->SetTextColor(179, 38, 30);
        $pdf->SetFont('Helvetica', 'B', 12);
        $pdf->put(self::LEFT, $y + 7, self::WIDTH, 15, $clean ? 'SIN HALLAZGOS PENDIENTES' : $audit->findings_count.($audit->findings_count === 1 ? ' HALLAZGO PENDIENTE' : ' HALLAZGOS PENDIENTES').' ('.$audit->critical_count.($audit->critical_count === 1 ? ' CRÍTICO)' : ' CRÍTICOS)'), 'C');
        $pdf->SetTextColor(40);
        $pdf->SetFont('Helvetica', '', 8);
        $pdf->put(self::LEFT, $y + 24, self::WIDTH, 11, $audit->acknowledged_count.($audit->acknowledged_count === 1 ? ' hallazgo aceptado' : ' hallazgos aceptados').' con su motivo.', 'C');
        $pdf->SetTextColor(0);
        $y += 52;

        if ($audit->notes !== null) {
            $pdf->SetFont('Helvetica', 'B', 8);
            $pdf->put(self::LEFT, $y, self::WIDTH, 10, 'OBSERVACIONES');
            $pdf->SetFont('Helvetica', '', 8.5);
            $lines = $pdf->wrap(self::LEFT, $y + 12, self::WIDTH, 11, $audit->notes, 8);
            $y += 16 + $lines * 11;
        }

        /** @var list<array{label: string, severity: string, description: string, findings: list<array{subject: string, detail: string, acknowledgement: array{reason: string, by: string|null, at: string}|null}>}> $checks */
        $checks = $audit->snapshot['checks'] ?? [];

        if ($checks === []) {
            $pdf->SetFont('Helvetica', '', 8.5);
            $pdf->wrap(self::LEFT, $y + 10, self::WIDTH, 11, 'Esta auditoría se ejecutó en el sistema anterior: conserva su resultado y sus observaciones, pero no el detalle de cada hallazgo.', 3);

            return $pdf->Output('S');
        }

        foreach ($checks as $check) {
            $y = $this->check($pdf, $y + 8, $check);
        }

        return $pdf->Output('S');
    }

    /**
     * @param  array{label: string, severity: string, description: string, findings: list<array{subject: string, detail: string, acknowledgement: array{reason: string, by: string|null, at: string}|null}>}  $check
     */
    private function check(Document $pdf, float $y, array $check): float
    {
        $y = $this->room($pdf, $y, 60);
        $pdf->box(self::LEFT, $y, self::WIDTH, 18, 0.75, 0, 238);
        $pdf->SetFont('Helvetica', 'B', 8.5);
        $pdf->put(self::LEFT + 6, $y, 380, 18, $check['label']);
        $pdf->SetFont('Helvetica', 'B', 7);
        $pdf->put(self::LEFT + 400, $y, 138, 18, self::SEVERITY[$check['severity']] ?? '', 'R');
        $y += 22;

        $pdf->SetFont('Helvetica', '', 7.5);
        $pdf->SetTextColor(70);
        $y += $pdf->wrap(self::LEFT + 6, $y, self::WIDTH - 12, 9.5, $check['description'], 3) * 9.5 + 3;
        $pdf->SetTextColor(0);

        if ($check['findings'] === []) {
            $pdf->SetFont('Helvetica', 'B', 7.5);
            $pdf->SetTextColor(22, 101, 52);
            $pdf->put(self::LEFT + 6, $y, self::WIDTH, 11, 'SE CUMPLE: no hay nada que reportar.');
            $pdf->SetTextColor(0);

            return $y + 14;
        }

        foreach ($check['findings'] as $finding) {
            $y = $this->room($pdf, $y, 46);
            $pdf->SetFont('Helvetica', 'B', 8);
            $pdf->put(self::LEFT + 6, $y, self::WIDTH - 12, 11, $finding['subject']);
            $pdf->SetFont('Helvetica', '', 8);
            $y += 11 + $pdf->wrap(self::LEFT + 6, $y + 11, self::WIDTH - 12, 10, $finding['detail'], 3) * 10;

            if ($finding['acknowledgement'] !== null) {
                $ack = $finding['acknowledgement'];
                $pdf->SetFont('Helvetica', 'I', 7.5);
                $pdf->SetTextColor(22, 101, 52);
                $by = $ack['by'] === null ? '' : ' por '.$ack['by'];
                $y += $pdf->wrap(self::LEFT + 14, $y + 1, self::WIDTH - 20, 9.5, 'ACEPTADO'.$by.' el '.Carbon::parse($ack['at'])->format('d/m/Y').': '.$ack['reason'], 3) * 9.5 + 1;
                $pdf->SetTextColor(0);
            } elseif ($check['severity'] !== 'info') {
                $pdf->SetFont('Helvetica', 'B', 7.5);
                $pdf->SetTextColor(179, 38, 30);
                $pdf->put(self::LEFT + 14, $y + 1, self::WIDTH - 20, 10, 'PENDIENTE');
                $pdf->SetTextColor(0);
                $y += 11;
            }

            $pdf->rule(self::LEFT, $y + 3, self::LEFT + self::WIDTH, $y + 3, 0.3, 190);
            $y += 7;
        }

        return $y;
    }

    private function room(Document $pdf, float $y, float $needed): float
    {
        if ($y + $needed <= self::BOTTOM) {
            return $y;
        }

        $pdf->AddPage();

        return 40.0;
    }

    private function footerLine(Audit $audit): string
    {
        $code = strtoupper(implode('-', str_split(substr(Canonical::hash([
            'ran_at' => $audit->ran_at->toIso8601String(),
            'status' => $audit->status,
            'counts' => [$audit->findings_count, $audit->critical_count, $audit->acknowledged_count],
            'snapshot' => $audit->snapshot,
            'notes' => $audit->notes,
        ]), 0, 16), 4)));
        $head = $this->sealed->head();

        return "Código de verificación {$code}".($head === null ? '' : ' · Cadena sellada: bloque '.$head['height'].' '.substr($head['hash'], 0, 16)).' · Impreso el '.Carbon::now()->format('d/m/Y H:i');
    }
}
