<?php

namespace App\Modules\Shared\Pdf;

use FPDF;

/**
 * The drawing surface of every printed document: FPDF in points, with the few things the library
 * does not have (circles, rotation, a diagonal watermark, UTF-8 text, images with transparency).
 *
 * Text is converted from UTF-8 to Windows-1252, which is what the core fonts (Helvetica, Times)
 * speak: it has every Spanish letter and the short dash. Glyphs it lacks (arrows, circles, the
 * minus sign) are never printed as characters: draw them.
 *
 * Coordinates are absolute and from the top left; documents place what they draw instead of
 * flowing it, because these are forms that replace paper and must land where the paper has it.
 */
final class Document extends FPDF
{
    /** A line printed at the foot of every page, with "Página n de N" under it; null prints nothing. */
    public ?string $footerLine = null;

    /**
     * @param  'P'|'L'  $orientation
     * @param  'Letter'|'A4'|array{0: float, 1: float}  $size  width and height in points, portrait
     */
    public function __construct(string $orientation = 'P', string|array $size = 'Letter')
    {
        parent::__construct($orientation, 'pt', $size);
        $this->SetMargins(0, 0, 0);
        $this->SetAutoPageBreak(false);
        $this->SetCreator('Iglesia Total');
    }

    public function Footer(): void
    {
        if ($this->footerLine === null) {
            return;
        }

        $this->SetFont('Helvetica', '', 6.5);
        $this->SetTextColor(100);
        $this->put(34, $this->h - 34, $this->w - 68, 8, $this->footerLine, 'C');
        $this->put(34, $this->h - 25, $this->w - 68, 8, 'Página '.$this->PageNo().' de {nb}', 'C');
        $this->SetTextColor(0);
    }

    /**
     * UTF-8 text as the core fonts read it.
     */
    public static function encode(string $text): string
    {
        return mb_convert_encoding($text, 'Windows-1252', 'UTF-8');
    }

    /**
     * Text in a box at an absolute position; long text is cut with "..." instead of overflowing.
     *
     * @param  'L'|'C'|'R'  $align
     */
    public function put(float $x, float $y, float $width, float $height, string $text, string $align = 'L', string|int $border = 0): void
    {
        $this->SetXY($x, $y);
        $this->Cell($width, $height, self::encode($this->fit($text, $width)), $border, 0, $align);
    }

    /**
     * Text wrapped inside a box, as many lines as fit; the rest is cut with "...".
     *
     * @param  'L'|'C'|'R'  $align
     */
    public function wrap(float $x, float $y, float $width, float $lineHeight, string $text, int $maxLines, string $align = 'L'): int
    {
        $lines = $this->lines($text, $width);

        if (count($lines) > $maxLines) {
            $lines = array_slice($lines, 0, $maxLines);
            $lines[$maxLines - 1] = $this->fit($lines[$maxLines - 1].'...', $width);
        }

        foreach ($lines as $i => $line) {
            $this->put($x, $y + $i * $lineHeight, $width, $lineHeight, $line, $align);
        }

        return count($lines);
    }

    /**
     * The lines a text takes inside a width (words are never cut; an overlong word is cut by fit()).
     *
     * @return list<string>
     */
    public function lines(string $text, float $width): array
    {
        $lines = [];
        $line = '';

        foreach (preg_split('/\s+/u', trim($text), -1, PREG_SPLIT_NO_EMPTY) ?: [] as $word) {
            $candidate = $line === '' ? $word : "{$line} {$word}";

            if ($line !== '' && $this->width($candidate) > $width) {
                $lines[] = $line;
                $line = $word;
            } else {
                $line = $candidate;
            }
        }

        if ($line !== '') {
            $lines[] = $line;
        }

        return $lines;
    }

    /**
     * Width of a text in the current font, in points.
     */
    public function width(string $text): float
    {
        return $this->GetStringWidth(self::encode($text));
    }

    /**
     * The text, cut with "..." when it does not fit the width.
     */
    public function fit(string $text, float $width): string
    {
        if ($this->width($text) <= $width) {
            return $text;
        }

        while ($text !== '' && $this->width($text.'...') > $width) {
            $text = mb_substr($text, 0, -1);
        }

        return $text.'...';
    }

    /**
     * A straight line of a given thickness and gray (0 black .. 255 white).
     */
    public function rule(float $x1, float $y1, float $x2, float $y2, float $thickness = 0.5, int $gray = 0): void
    {
        $this->SetLineWidth($thickness);
        $this->SetDrawColor($gray);
        $this->Line($x1, $y1, $x2, $y2);
    }

    /**
     * A rectangle, outlined and optionally filled with a gray.
     */
    public function box(float $x, float $y, float $width, float $height, float $thickness = 0.5, int $gray = 0, ?int $fill = null): void
    {
        $this->SetLineWidth($thickness);
        $this->SetDrawColor($gray);

        if ($fill !== null) {
            $this->SetFillColor($fill);
        }

        $this->Rect($x, $y, $width, $height, $fill === null ? 'D' : 'DF');
    }

    /**
     * A circle: outlined, or filled when $filled.
     */
    public function circle(float $cx, float $cy, float $radius, bool $filled = false, float $thickness = 0.8): void
    {
        $this->SetLineWidth($thickness);
        $k = $this->k;
        $h = $this->h;
        $c = 0.5523 * $radius;
        $points = [
            [$cx + $radius, $cy, $cx + $radius, $cy + $c, $cx + $c, $cy + $radius, $cx, $cy + $radius],
            [$cx, $cy + $radius, $cx - $c, $cy + $radius, $cx - $radius, $cy + $c, $cx - $radius, $cy],
            [$cx - $radius, $cy, $cx - $radius, $cy - $c, $cx - $c, $cy - $radius, $cx, $cy - $radius],
            [$cx, $cy - $radius, $cx + $c, $cy - $radius, $cx + $radius, $cy - $c, $cx + $radius, $cy],
        ];
        $this->_out(sprintf('%.2F %.2F m', $cx * $k + $radius * $k, ($h - $cy) * $k));

        foreach ($points as $p) {
            $this->_out(sprintf('%.2F %.2F %.2F %.2F %.2F %.2F c', $p[2] * $k, ($h - $p[3]) * $k, $p[4] * $k, ($h - $p[5]) * $k, $p[6] * $k, ($h - $p[7]) * $k));
        }

        $this->_out($filled ? 'B' : 'S');
    }

    /**
     * A checkbox, crossed when checked.
     */
    public function checkbox(float $x, float $y, bool $checked, float $size = 9): void
    {
        $this->box($x, $y, $size, $size, 0.8, 17);

        if ($checked) {
            $this->rule($x + 2, $y + 2, $x + $size - 2, $y + $size - 2, 0.9, 17);
            $this->rule($x + $size - 2, $y + 2, $x + 2, $y + $size - 2, 0.9, 17);
        }
    }

    /**
     * A radio button, dotted when chosen.
     */
    public function radio(float $x, float $y, bool $checked, float $size = 8): void
    {
        $this->SetDrawColor(17);
        $this->SetFillColor(17);
        $this->circle($x + $size / 2, $y + $size / 2, $size / 2 - 0.6);

        if ($checked) {
            $this->circle($x + $size / 2, $y + $size / 2, $size / 4, true);
        }
    }

    /**
     * Text turned around a point (degrees counter-clockwise), for the RIF on the edge of a form and
     * for watermarks.
     */
    public function turned(float $angle, float $x, float $y, callable $draw): void
    {
        $this->_out('q');
        $a = $angle * M_PI / 180;
        $c = cos($a);
        $s = sin($a);
        $k = $this->k;
        $cx = $x * $k;
        $cy = ($this->h - $y) * $k;
        $this->_out(sprintf('%.5F %.5F %.5F %.5F %.2F %.2F cm 1 0 0 1 %.2F %.2F cm', $c, $s, -$s, $c, $cx, $cy, -$cx, -$cy));
        $draw();
        $this->_out('Q');
    }

    /**
     * A big pale word across the page ("BORRADOR", "ANULADO"), drawn where the cursor leaves it.
     */
    public function watermark(string $word, float $size = 70, int $gray = 225): void
    {
        $this->SetFont('Helvetica', 'B', $size);
        $this->SetTextColor($gray);
        $x = $this->w / 2;
        $y = $this->h / 2;
        $width = $this->width($word);

        $this->turned(35, $x, $y, function () use ($word, $x, $y, $width, $size): void {
            $this->Text($x - $width / 2, $y + $size * 0.3, self::encode($word));
        });
        $this->SetTextColor(0);
    }

    /**
     * An image given as bytes (WebP, PNG, JPEG, whatever GD reads) as a PNG, keeping transparency.
     * Nothing is drawn when the bytes are not an image.
     */
    public function imageFromBytes(string $bytes, float $x, float $y, float $width = 0, float $height = 0): bool
    {
        $source = @imagecreatefromstring($bytes);

        if ($source === false) {
            return false;
        }

        imagesavealpha($source, true);
        $file = tempnam(sys_get_temp_dir(), 'pdf-img-');

        try {
            if ($file === false || ! imagepng($source, $file)) {
                return false;
            }

            $this->Image($file, $x, $y, $width, $height, 'PNG');

            return true;
        } finally {
            if (is_string($file)) {
                @unlink($file);
            }
        }
    }
}
