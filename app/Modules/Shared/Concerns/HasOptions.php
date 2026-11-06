<?php

namespace App\Modules\Shared\Concerns;

/**
 * For backed enums that have a label(): the list the frontend uses for selects and filters.
 */
trait HasOptions
{
    /**
     * @return list<array{value: string, label: string}>
     */
    public static function options(): array
    {
        return array_map(
            fn (self $case): array => ['value' => $case->value, 'label' => $case->label()],
            self::cases(),
        );
    }
}
