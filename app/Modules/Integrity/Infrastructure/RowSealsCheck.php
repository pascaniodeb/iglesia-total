<?php

namespace App\Modules\Integrity\Infrastructure;

use App\Modules\Integrity\Application\Seals\VerifyRowSeals;
use App\Modules\Integrity\Domain\IntegrityCheck;

/**
 * Every row of the sealed models still has the seal it was given: a row changed, erased or added
 * outside the Actions is found even if the triggers of the database were switched off. It holds
 * trivially while sealing is off (no key).
 */
final class RowSealsCheck implements IntegrityCheck
{
    public function __construct(private readonly VerifyRowSeals $verify) {}

    public function id(): string
    {
        return 'seals.rows';
    }

    public function title(): string
    {
        return __('Every sealed row still has the seal it was given');
    }

    public function findings(): array
    {
        $result = ($this->verify)();
        $findings = [];

        foreach (['changed' => 'The row :row no longer matches its seal: it was changed outside the Actions.', 'unsealed' => 'The row :row has no seal: run integrity:seal-rows.', 'gone' => 'The row :row was erased: its seal is all that is left.'] as $kind => $message) {
            foreach ($result[$kind] as $row) {
                $findings[] = __($message, ['row' => $row]);
            }

            $hidden = $result['counts'][$kind] - count($result[$kind]);

            if ($hidden > 0) {
                $findings[] = __(':count more rows of this kind are not listed.', ['count' => $hidden]);
            }
        }

        return $findings;
    }
}
