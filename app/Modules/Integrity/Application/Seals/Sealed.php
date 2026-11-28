<?php

namespace App\Modules\Integrity\Application\Seals;

/**
 * A sealed model: each time an Action saves, deletes or restores a row, a seal (HMAC-SHA256) of the
 * row as it stands in the database is recorded. If someone changes or erases the row outside the
 * Actions (direct SQL, even with the triggers switched off), verification notices.
 *
 * A module registers its sealed models in SealedModels from its own provider, and uses the
 * SealsRows trait on the model.
 */
interface Sealed
{
    /**
     * Columns left out of the seal because they change by technical paths no Action records.
     *
     * @return list<string>
     */
    public static function unsealedColumns(): array;
}
