<?php

namespace App\Console\Commands;

use RuntimeException;

/**
 * Thrown inside an import's transaction when the totals of the old and the new system are not
 * equal to the cent: it undoes everything the import wrote and carries the figures for the report.
 */
final class ImportDifference extends RuntimeException
{
    /**
     * @param  array<string, array{0: string, 1: string}>  $report  per figure: [old system, new system]
     */
    public function __construct(public readonly array $report)
    {
        parent::__construct('The totals of the old and the new system differ.');
    }
}
