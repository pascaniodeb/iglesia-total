<?php

namespace App\Modules\Church\Application;

use App\Modules\Shared\Pdf\Document;

/**
 * Public contract for the head of every printed document: the congregation's identity and logo,
 * drawn on a page the module that prints the document owns. Nothing about the church is written in
 * the code; fields that are empty are simply not drawn.
 */
interface ChurchLetterhead
{
    /**
     * Draws the letterhead at the top of the current page and returns the y where the document's
     * own content can start.
     *
     * @throws \LogicException when the church settings were never filled in
     */
    public function draw(Document $pdf, LetterheadVariant $variant, string $title = ''): float;
}
