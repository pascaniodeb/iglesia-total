<?php

namespace App\Modules\Church\Application;

/**
 * The letterheads of the printed forms. Each one keeps the measures of the paper it replaces; they
 * share where the church's data is read, not how a form is laid out.
 */
enum LetterheadVariant: string
{
    /** Portrait letter: centred block, the denomination's RIF under it, the title without a frame (accounting and period reports). */
    case Stacked = 'stacked';

    /** Landscape letter of the weekly income form: the RIF and the church code boxed in the corner, the title inside a frame. */
    case Framed = 'framed';

    /** Landscape half-letter of the IPUV receipt book: the RIF running up the left edge, the logo beside the text. */
    case Receipt = 'receipt';
}
