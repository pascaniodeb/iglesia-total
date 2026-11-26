<?php

namespace App\Modules\Church\Infrastructure\Pdf;

use App\Modules\Church\Application\ChurchIdentity;
use App\Modules\Church\Application\ChurchLetterhead;
use App\Modules\Church\Application\ChurchProfile;
use App\Modules\Church\Application\LetterheadVariant;
use App\Modules\Church\Infrastructure\BrandingStorage;
use App\Modules\Shared\Pdf\Document;
use LogicException;

final class FpdfChurchLetterhead implements ChurchLetterhead
{
    private const float MARGIN = 34;

    public function __construct(private readonly ChurchIdentity $identity, private readonly BrandingStorage $branding) {}

    public function draw(Document $pdf, LetterheadVariant $variant, string $title = ''): float
    {
        $church = $this->identity->profile() ?? throw new LogicException('The church settings have not been filled in.');

        return match ($variant) {
            LetterheadVariant::Stacked => $this->stacked($pdf, $church, $title),
            LetterheadVariant::Receipt => $this->receipt($pdf, $church),
        };
    }

    private function stacked(Document $pdf, ChurchProfile $church, string $title): float
    {
        $width = $pdf->GetPageWidth();
        $y = 30.0;

        $this->logo($pdf, $church, self::MARGIN, $y, 46);

        $pdf->SetFont('Times', 'B', 12.5);
        $pdf->put(0, $y, $width, 15, $church->denomination, 'C');
        $y += 17;

        if ($church->headquarters !== '') {
            $pdf->SetFont('Helvetica', 'B', 9);
            $pdf->put(0, $y, $width, 11, $church->headquarters, 'C');
            $y += 14;
        }

        if ($church->registrationNote !== '') {
            $pdf->SetFont('Helvetica', '', 6.5);
            $lines = $pdf->wrap(($width - 340) / 2, $y, 340, 8, $church->registrationNote, 3, 'C');
            $y += $lines * 8 + 3;
        }

        $identity = array_filter([
            $church->denominationRif !== '' ? 'RIF: '.$church->denominationRif : null,
        ]);

        if ($identity !== []) {
            $pdf->SetFont('Helvetica', 'B', 7.5);
            $pdf->put(0, $y, $width, 9, implode('   ', $identity), 'C');
            $y += 11;
        }

        $y = max($y, 30 + 46 + 2);

        if ($title !== '') {
            $pdf->SetFont('Times', 'B', 11);
            $pdf->put(0, $y + 6, $width, 13, $title, 'C');
            $y += 22;
        }

        return $y + 6;
    }

    private function receipt(Document $pdf, ChurchProfile $church): float
    {
        $width = $pdf->GetPageWidth();
        $top = 8.0;

        if ($church->denominationRif !== '') {
            $pdf->SetFont('Helvetica', 'B', 6.5);
            $pdf->SetTextColor(17);
            $pdf->turned(90, 22, 64, function () use ($pdf, $church): void {
                $pdf->Text(22, 64, Document::encode('RIF. '.$church->denominationRif));
            });
        }

        $this->logo($pdf, $church, 34, $top, 48);

        $left = 94;
        $textWidth = $width - $left - 16;
        $y = $top + 2;

        $pdf->SetFont('Times', 'B', 11);
        $pdf->put($left, $y, $textWidth, 13, $church->denomination, 'C');
        $y += 14;

        if ($church->headquarters !== '') {
            $pdf->SetFont('Helvetica', 'B', 8);
            $pdf->put($left, $y, $textWidth, 10, $church->headquarters, 'C');
            $y += 11;
        }

        if ($church->registrationNote !== '') {
            $pdf->SetFont('Helvetica', '', 5.8);
            $lines = $pdf->wrap($left, $y, $textWidth, 7, $church->registrationNote, 2, 'C');
            $y += $lines * 7 + 2;
        }

        $line = array_filter([
            $church->name !== '' ? $church->name : null,
            $church->rif !== '' ? 'RIF '.$church->rif : null,
            $church->code !== '' ? 'Cód. '.$church->code : null,
        ]);

        if ($line !== []) {
            $pdf->SetFont('Helvetica', 'B', 6.5);
            $pdf->put($left, $y, $textWidth, 8, implode('  ·  ', $line), 'C');
            $y += 9;
        }

        return max($y, $top + 48) + 4;
    }

    private function logo(Document $pdf, ChurchProfile $church, float $x, float $y, float $size): void
    {
        if ($church->lightLogo === null) {
            return;
        }

        $bytes = $this->branding->read($church->lightLogo->path);

        if ($bytes !== null) {
            $pdf->imageFromBytes($bytes, $x, $y, $size, $size);
        }
    }
}
