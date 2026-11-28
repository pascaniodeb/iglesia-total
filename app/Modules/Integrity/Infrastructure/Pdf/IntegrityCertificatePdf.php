<?php

namespace App\Modules\Integrity\Infrastructure\Pdf;

use App\Modules\Church\Application\ChurchIdentity;
use App\Modules\Church\Application\ChurchLetterhead;
use App\Modules\Church\Application\LetterheadVariant;
use App\Modules\Integrity\Domain\Ledger\Canonical;
use App\Modules\Shared\Pdf\Document;
use App\Modules\Shared\Pdf\Format;
use Illuminate\Support\Carbon;
use LogicException;

/**
 * The certificate of the sealed ledger on a portrait letter page: what was verified, when, with
 * what result, the head of the chain written out in full (so it can be kept outside the system and
 * compared later) and every control of the books with whether it held. It certifies a verification
 * made at the moment of printing, never an older one. A certificate with differences says so
 * loudly: it is never printed as if all were well.
 */
final class IntegrityCertificatePdf
{
    private const float LEFT = 34;

    private const float WIDTH = 544;

    public function __construct(
        private readonly ChurchLetterhead $letterhead,
        private readonly ChurchIdentity $identity,
    ) {}

    /**
     * @param  array{chain: array<string, mixed>, checks: list<array{id: string, title: string, passed: bool, findings: list<string>}>, passed: bool}  $verification
     * @param  array{genesis: int, weekly: int, monthly: int, started_at: string|null, anchored: array{ledger: mixed, activity: mixed}}  $counts
     */
    public function render(array $verification, array $counts): string
    {
        $church = $this->identity->profile() ?? throw new LogicException('The church settings have not been filled in.');
        $chain = $verification['chain'];
        $passed = $verification['passed'];
        $now = Carbon::now();

        /** @var array{blocks: int, height: int|null, head_hash: string|null, legacy: array{blocks: int, height: int|null, head_hash: string|null, intact: bool}, intact: bool, matches_books: bool} $chain */
        $pdf = new Document('P', 'Letter');
        $pdf->AliasNbPages();
        $pdf->SetTitle(Document::encode('Certificado de integridad del libro sellado'));
        $pdf->SetAuthor(Document::encode($church->name));
        $pdf->footerLine = 'Código de verificación '.$this->code($chain, $verification['checks']).' · Impreso el '.$now->format('d/m/Y H:i');
        $pdf->AddPage();

        if (! $passed) {
            $pdf->watermark('CON DIFERENCIAS', 52, 235);
        }

        $y = $this->letterhead->draw($pdf, LetterheadVariant::Stacked, 'CERTIFICADO DE INTEGRIDAD DEL LIBRO SELLADO');

        $pdf->SetFont('Helvetica', '', 9);
        $y += $pdf->wrap(self::LEFT, $y + 4, self::WIDTH, 12, "La {$church->name}, código {$church->code}, hace constar que el {$now->format('d/m/Y')} a las {$now->format('H:i')} se verificó el libro sellado de sus finanzas, con el resultado que sigue.", 4) * 12 + 14;

        $y = $this->result($pdf, $y, $passed, $chain);
        $y = $this->chainData($pdf, $y + 14, $chain, $counts);
        $y = $this->controls($pdf, $y + 14, $verification['checks']);

        $pdf->SetFont('Helvetica', '', 7.5);
        $y += $pdf->wrap(self::LEFT, $y + 14, self::WIDTH, 10, 'Para comprobarlo de forma independiente: la huella de cada bloque es el SHA-256 de su altura, la huella del bloque anterior, la raíz de sus registros y la huella de su contenido. Quien conserve la huella del último bloque escrita arriba puede detectar, más adelante, cualquier cambio en lo ya sellado.', 4) * 10 + 14;

        $y = max($y + 30, 640.0);
        $slot = self::WIDTH / 2;

        foreach (['PASTOR', 'CONTRALOR'] as $i => $label) {
            $center = self::LEFT + $slot * $i + $slot / 2;
            $pdf->rule($center - $slot * 0.38, $y, $center + $slot * 0.38, $y, 0.75);
            $pdf->SetFont('Helvetica', 'B', 8);
            $pdf->put($center - $slot / 2, $y + 4, $slot, 10, $label, 'C');
        }

        return $pdf->Output('S');
    }

    /**
     * @param  array<string, mixed>  $chain
     */
    private function result(Document $pdf, float $y, bool $passed, array $chain): float
    {
        $pdf->box(self::LEFT, $y, self::WIDTH, 44, 1.25, $passed ? 40 : 120);
        $passed ? $pdf->SetTextColor(22, 101, 52) : $pdf->SetTextColor(179, 38, 30);
        $pdf->SetFont('Helvetica', 'B', 12);
        $pdf->put(self::LEFT, $y + 8, self::WIDTH, 15, $passed ? 'CADENA ÍNTEGRA Y CUADRADA CON LOS LIBROS' : 'SE ENCONTRARON DIFERENCIAS', 'C');
        $pdf->SetTextColor(40);
        $pdf->SetFont('Helvetica', '', 8);
        $detail = match (true) {
            ! $chain['intact'] => 'Un bloque no coincide con su huella: la cadena fue alterada.',
            ! $chain['matches_books'] => 'Los libros cambiaron después de sellarse algún período.',
            ! $passed => 'Algún control de los libros no se cumple (ver el detalle abajo).',
            default => 'Cada bloque coincide con su huella y con lo que hoy dicen los libros.',
        };
        $pdf->put(self::LEFT, $y + 26, self::WIDTH, 11, $detail, 'C');
        $pdf->SetTextColor(0);

        return $y + 44;
    }

    /**
     * @param  array<string, mixed>  $chain
     * @param  array{genesis: int, weekly: int, monthly: int, started_at: string|null, anchored: array{ledger: mixed, activity: mixed}}  $counts
     */
    private function chainData(Document $pdf, float $y, array $chain, array $counts): float
    {
        $pdf->SetFont('Helvetica', 'B', 9);
        $pdf->put(self::LEFT, $y, self::WIDTH, 12, 'DATOS DE LA CADENA');
        $y += 18;

        $rows = [
            ['Bloques sellados', $chain['blocks'].' ('.$counts['weekly'].' semanas, '.$counts['monthly'].' meses y el bloque de apertura)'],
            ['Abierta el', $counts['started_at'] === null ? '—' : Format::date($counts['started_at'])],
            ['Último bloque', $chain['height'] === null ? '—' : '#'.$chain['height']],
        ];

        foreach ($rows as [$label, $value]) {
            $pdf->SetFont('Helvetica', 'B', 7.5);
            $pdf->put(self::LEFT, $y, 120, 11, $label.':');
            $pdf->SetFont('Helvetica', '', 8.5);
            $pdf->put(self::LEFT + 122, $y, self::WIDTH - 122, 11, (string) $value);
            $y += 14;
        }

        $y = $this->hash($pdf, $y, 'Huella del último bloque', $chain['head_hash']);

        foreach (['ledger' => 'Último anclaje externo del libro sellado', 'activity' => 'Último anclaje externo de la bitácora'] as $chainKey => $label) {
            $at = $counts['anchored'][$chainKey];
            $pdf->SetFont('Helvetica', 'B', 7.5);
            $pdf->put(self::LEFT, $y + 2, 200, 11, $label.':');
            $pdf->SetFont('Helvetica', '', 8.5);
            $pdf->put(self::LEFT + 202, $y + 2, self::WIDTH - 202, 11, is_string($at) ? Carbon::parse($at)->format('d/m/Y H:i').' (confirmado fuera de la iglesia)' : 'ninguno: el anclaje externo no está activo o aún no confirma');
            $y += 14;
        }

        /** @var array{blocks: int, height: int|null, head_hash: string|null} $legacy */
        $legacy = $chain['legacy'];

        if ($legacy['blocks'] > 0) {
            $pdf->SetFont('Helvetica', 'B', 7.5);
            $pdf->put(self::LEFT, $y + 4, 120, 11, 'Cadena anterior:');
            $pdf->SetFont('Helvetica', '', 8.5);
            $pdf->put(self::LEFT + 122, $y + 4, self::WIDTH - 122, 11, $legacy['blocks'].' bloques archivados, hasta el #'.$legacy['height']);
            $y = $this->hash($pdf, $y + 18, 'Huella del último bloque archivado', $legacy['head_hash']);
        }

        return $y;
    }

    private function hash(Document $pdf, float $y, string $label, ?string $hash): float
    {
        $pdf->SetFont('Helvetica', 'B', 7.5);
        $pdf->put(self::LEFT, $y, 200, 11, $label.':');
        $pdf->box(self::LEFT, $y + 13, self::WIDTH, 24, 0.5, 150, 245);
        $pdf->SetFont('Courier', '', 8.5);

        foreach (str_split($hash ?? '—', 32) as $i => $part) {
            $pdf->put(self::LEFT + 8, $y + 15 + $i * 10, self::WIDTH - 16, 10, $part);
        }

        return $y + 40;
    }

    /**
     * @param  list<array{id: string, title: string, passed: bool, findings: list<string>}>  $checks
     */
    private function controls(Document $pdf, float $y, array $checks): float
    {
        $pdf->SetFont('Helvetica', 'B', 9);
        $pdf->put(self::LEFT, $y, self::WIDTH, 12, 'CONTROLES DE LOS LIBROS');
        $y += 18;

        foreach ($checks as $check) {
            $pdf->SetFont('Helvetica', 'B', 7.5);
            $check['passed'] ? $pdf->SetTextColor(22, 101, 52) : $pdf->SetTextColor(179, 38, 30);
            $pdf->put(self::LEFT, $y, 56, 11, $check['passed'] ? 'SE CUMPLE' : 'FALLA');
            $pdf->SetTextColor(17);
            $pdf->SetFont('Helvetica', '', 8);
            $lines = $pdf->wrap(self::LEFT + 62, $y, self::WIDTH - 62, 10, $check['title'], 2);
            $y += $lines * 10 + 4;

            foreach (array_slice($check['findings'], 0, 3) as $finding) {
                $pdf->SetTextColor(179, 38, 30);
                $pdf->SetFont('Helvetica', '', 7);
                $pdf->put(self::LEFT + 62, $y, self::WIDTH - 62, 9, $finding);
                $y += 10;
            }

            $pdf->SetTextColor(0);
        }

        return $y;
    }

    /**
     * @param  array<string, mixed>  $chain
     * @param  list<array{id: string, title: string, passed: bool, findings: list<string>}>  $checks
     */
    private function code(array $chain, array $checks): string
    {
        return strtoupper(implode('-', str_split(substr(Canonical::hash([
            'height' => $chain['height'],
            'head' => $chain['head_hash'],
            'intact' => $chain['intact'],
            'matches' => $chain['matches_books'],
            'checks' => array_map(fn (array $check): array => [$check['id'], $check['passed']], $checks),
        ]), 0, 16), 4)));
    }
}
