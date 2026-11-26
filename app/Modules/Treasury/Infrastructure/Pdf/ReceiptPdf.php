<?php

namespace App\Modules\Treasury\Infrastructure\Pdf;

use App\Modules\Church\Application\ChurchLetterhead;
use App\Modules\Church\Application\LetterheadVariant;
use App\Modules\Shared\Pdf\Document;
use App\Modules\Shared\Pdf\Format;
use App\Modules\Treasury\Application\Receipts\ReceiptAmountInWords;
use App\Modules\Treasury\Domain\Receipts\PaymentMethod;
use App\Modules\Treasury\Domain\Receipts\ReceiptNumber;
use App\Modules\Treasury\Domain\Receipts\ReceiptType;
use App\Modules\Treasury\Infrastructure\Models\Receipt;
use Illuminate\Support\Carbon;

/**
 * The receipt of the IPUV book, drawn as the paper form: half-letter landscape (612 x 396 pt),
 * EGRESO/INGRESO with its box ticked, the accounting code, the date in three boxes, the body in a
 * frame with inner lines and the two signature blocks. What is printed is what is handed over and
 * filed, so the document is the paper, not an interpretation of it; an empty field prints its blank
 * line, because on a form the empty line is information.
 *
 * It is never stored: a receipt cannot be edited (it is voided), so the same paper can always be
 * drawn again, and a voided one changes (the ANULADO band), which is one more reason not to freeze
 * it. The verification code is the start of the SHA-256 of the content the monthly seal binds.
 */
final class ReceiptPdf
{
    private const float LEFT = 28;

    private const float WIDTH = 556;

    private const float ROW = 18;

    public function __construct(private readonly ChurchLetterhead $letterhead) {}

    public function render(Receipt $receipt, string $accountCode): string
    {
        $receipt->loadMissing('void');
        $number = ReceiptNumber::format($receipt->number);
        $isVoid = $receipt->isVoid();

        $pdf = new Document('L', [396, 612]);
        $pdf->SetTitle(Document::encode("Comprobante N.º {$number}"));
        $pdf->SetSubject(Document::encode('Comprobante '.($receipt->type === ReceiptType::Expense ? 'de egreso' : 'de ingreso')." {$number}"));
        $pdf->AddPage();

        if ($isVoid) {
            $pdf->watermark('ANULADO', 62, 232);
        }

        $y = $this->letterhead->draw($pdf, LetterheadVariant::Receipt);
        $y = $this->heading($pdf, $y, $receipt, $number, $accountCode);

        if ($receipt->void !== null) {
            $pdf->box(self::LEFT, $y, self::WIDTH, 14, 0.9, 60);
            $pdf->SetFont('Helvetica', 'B', 7.5);
            $pdf->SetTextColor(150, 20, 20);
            $pdf->put(self::LEFT + 6, $y, 70, 14, 'ANULADO');
            $pdf->SetFont('Helvetica', '', 7);
            $pdf->put(self::LEFT + 62, $y, self::WIDTH - 68, 14, 'Motivo: '.$receipt->void->reason.' · Fecha: '.Format::date($receipt->void->voided_at->toDateString()));
            $pdf->SetTextColor(0);
            $y += 18;
        }

        $y = $this->body($pdf, $y, $receipt);
        $this->signatures($pdf, $y + 8, $receipt);
        $this->footer($pdf, $receipt, $number);

        return $pdf->Output('S');
    }

    private function heading(Document $pdf, float $y, Receipt $receipt, string $number, string $accountCode): float
    {
        $pdf->SetTextColor(17);
        $pdf->SetFont('Times', 'B', 20);
        $pdf->put(self::LEFT, $y, 250, 22, 'COMPROBANTE');
        $pdf->SetFont('Helvetica', 'B', 15);
        $pdf->SetTextColor(192, 0, 0);
        $pdf->put(self::LEFT + self::WIDTH - 200, $y + 3, 200, 18, "N° {$number}", 'R');
        $pdf->SetTextColor(17);
        $y += 28;

        $pdf->SetFont('Helvetica', 'B', 7.5);
        $x = self::LEFT;
        $pdf->checkbox($x, $y + 2, $receipt->type === ReceiptType::Expense);
        $pdf->put($x + 13, $y, 40, 13, 'EGRESO');
        $pdf->checkbox($x + 62, $y + 2, $receipt->type === ReceiptType::Income);
        $pdf->put($x + 75, $y, 44, 13, 'INGRESO');
        $pdf->put($x + 132, $y, 90, 13, 'CÓDIGO CONTABLE');
        $pdf->box($x + 224, $y - 1, 64, 15, 0.8, 17);
        $pdf->SetFont('Helvetica', 'B', 8.5);
        $pdf->put($x + 224, $y - 1, 64, 15, $accountCode, 'C');

        [$year, $month, $day] = explode('-', $receipt->issued_on->toDateString());
        $pdf->SetFont('Helvetica', 'B', 7.5);
        $pdf->put(self::LEFT + 308, $y, 110, 13, 'Fecha de Emisión:', 'R');
        foreach ([['Día', $day, 36, 428], ['Mes', $month, 36, 468], ['Año', $year, 52, 508]] as [$label, $value, $width, $offset]) {
            $pdf->SetFont('Helvetica', '', 5.5);
            $pdf->put(self::LEFT + $offset, $y - 8, $width, 7, $label, 'C');
            $pdf->box(self::LEFT + $offset, $y - 1, $width, 15, 0.8, 17);
            $pdf->SetFont('Helvetica', 'B', 8.5);
            $pdf->put(self::LEFT + $offset, $y - 1, $width, 15, $value, 'C');
        }

        return $y + 20;
    }

    private function body(Document $pdf, float $y, Receipt $receipt): float
    {
        $top = $y;
        $isExpense = $receipt->type === ReceiptType::Expense;
        $amounts = ['ves' => $receipt->amount_ves, 'usd' => $receipt->amount_usd, 'cop' => $receipt->amount_cop];

        $y = $this->line($pdf, $y, self::ROW, 'TITULAR:', $receipt->holder);
        $y = $this->line($pdf, $y, self::ROW, 'HEMOS PAGADO A:', $isExpense ? $receipt->counterparty : null);
        $y = $this->line($pdf, $y, self::ROW, 'HEMOS RECIBIDO DE:', $isExpense ? null : $receipt->counterparty);

        $y = $this->lined($pdf, $y, 30, 'LA CANTIDAD DE (En Letras):', ReceiptAmountInWords::of($amounts), 2);

        $pdf->rule(self::LEFT, $y + self::ROW, self::LEFT + self::WIDTH, $y + self::ROW, 0.4, 150);
        foreach ([['Bolívares:', 'ves', 0], ['Dólares:', 'usd', 1], ['Pesos Colombianos:', 'cop', 2]] as [$label, $currency, $column]) {
            $x = self::LEFT + 6 + $column * 184;
            $pdf->SetFont('Helvetica', 'B', 7);
            $pdf->put($x, $y, 74, self::ROW, $label);
            $pdf->SetFont('Helvetica', '', 8.5);
            $pdf->put($x + ($column === 2 ? 82 : 52), $y, $column === 2 ? 90 : 118, self::ROW, $this->blankZero($amounts[$currency]), 'R');
        }
        $y += self::ROW;

        $y = $this->lined($pdf, $y, 28, 'POR CONCEPTO DE:', $receipt->concept, 2);

        $pdf->rule(self::LEFT, $y + self::ROW, self::LEFT + self::WIDTH, $y + self::ROW, 0.4, 150);
        $pdf->SetFont('Helvetica', 'B', 7);
        $pdf->put(self::LEFT + 6, $y, 80, self::ROW, 'FORMA DE PAGO:');
        $x = self::LEFT + 96;
        foreach ([[PaymentMethod::Cash, 'Efectivo', 66], [PaymentMethod::Deposit, 'Depósito', 66], [PaymentMethod::Transfer, 'Transferencia', 90]] as [$method, $label, $width]) {
            $pdf->radio($x, $y + 5, $receipt->payment_method === $method);
            $pdf->SetFont('Helvetica', '', 7.5);
            $pdf->put($x + 11, $y, $width, self::ROW, $label);
            $x += 11 + $width;
        }
        $y += self::ROW;

        $pdf->SetFont('Helvetica', 'B', 7);
        $pdf->put(self::LEFT + 6, $y, 34, self::ROW, 'Banco:');
        $pdf->put(self::LEFT + 190, $y, 36, self::ROW, 'N° Ref.:');
        $pdf->put(self::LEFT + 392, $y, 28, self::ROW, 'Fecha:');
        $pdf->SetFont('Helvetica', '', 8);
        $pdf->put(self::LEFT + 40, $y, 146, self::ROW, (string) $receipt->bank);
        $pdf->put(self::LEFT + 228, $y, 160, self::ROW, (string) $receipt->payment_reference);
        $pdf->put(self::LEFT + 422, $y, 130, self::ROW, $receipt->payment_date === null ? '' : Format::date($receipt->payment_date->toDateString()));
        $y += self::ROW;

        $pdf->box(self::LEFT, $top, self::WIDTH, $y - $top, 0.9, 17);

        return $y;
    }

    private function line(Document $pdf, float $y, float $height, string $label, ?string $value): float
    {
        $pdf->rule(self::LEFT, $y + $height, self::LEFT + self::WIDTH, $y + $height, 0.4, 150);
        $pdf->SetFont('Helvetica', 'B', 7);
        $pdf->put(self::LEFT + 6, $y, 130, $height, $label);
        $pdf->SetFont('Helvetica', '', 8.5);
        $pdf->put(self::LEFT + 138, $y, self::WIDTH - 144, $height, (string) $value);

        return $y + $height;
    }

    private function lined(Document $pdf, float $y, float $height, string $label, ?string $value, int $lines): float
    {
        $pdf->rule(self::LEFT, $y + $height, self::LEFT + self::WIDTH, $y + $height, 0.4, 150);
        $pdf->SetFont('Helvetica', 'B', 7);
        $pdf->put(self::LEFT + 6, $y, 130, 16, $label);
        $pdf->SetFont('Helvetica', '', 8.5);
        $pdf->wrap(self::LEFT + 138, $y + 3, self::WIDTH - 144, 11, (string) $value, $lines);

        return $y + $height;
    }

    private function signatures(Document $pdf, float $y, Receipt $receipt): void
    {
        foreach ([
            [self::LEFT, 'RECIBÍ CONFORME', $receipt->received_by_name, $receipt->received_by_id_number],
            [self::LEFT + 292, 'ENTREGUÉ CONFORME', $receipt->delivered_by_name, $receipt->delivered_by_id_number],
        ] as [$x, $title, $name, $idNumber]) {
            $pdf->SetFont('Helvetica', 'B', 7.5);
            $pdf->put($x, $y, 264, 11, $title, 'C');

            foreach ([['Nombre y Apellido:', $name, 0], ['N° C.I.:', $idNumber, 1], ['Firma:', null, 2]] as [$label, $value, $row]) {
                $lineY = $y + 12 + $row * 14;
                $pdf->SetFont('Helvetica', 'B', 6.5);
                $pdf->put($x, $lineY, 80, 13, $label);
                $labelWidth = $pdf->width($label) + 4;
                $pdf->rule($x + $labelWidth, $lineY + 12, $x + 264, $lineY + 12, 0.4, 80);
                $pdf->SetFont('Helvetica', '', 8);
                $pdf->put($x + $labelWidth + 2, $lineY, 262 - $labelWidth, 12, (string) $value);
            }
        }
    }

    private function footer(Document $pdf, Receipt $receipt, string $number): void
    {
        $code = strtoupper(implode('-', str_split(substr($receipt->content()->hash(), 0, 16), 4)));
        $pdf->SetFont('Helvetica', '', 5.5);
        $pdf->SetTextColor(110);
        $pdf->put(self::LEFT, 380, self::WIDTH, 8, "Comprobante N.º {$number} · Código de verificación {$code} · Impreso el ".Carbon::now()->format('d/m/Y H:i'), 'C');
        $pdf->SetTextColor(0);
    }

    /** A currency at zero prints blank, like the amount in letters leaves it out: the paper cannot show it in one place and not in the other. */
    private function blankZero(string $value): string
    {
        return preg_match('/^0*(\.0*)?$/', $value) === 1 ? '' : Format::amount($value);
    }
}
