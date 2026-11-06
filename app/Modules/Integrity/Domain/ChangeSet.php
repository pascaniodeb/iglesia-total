<?php

namespace App\Modules\Integrity\Domain;

use BackedEnum;
use DateTimeInterface;
use Stringable;

/**
 * What changed between two states of a record, ready to be written to the activity log.
 *
 * Personal data (document number, phone, address, birth date, pastor's notes) is never
 * copied into the log: the entry says that the field changed, not its old or new value.
 * The log is read by more people than the registry and it can never be erased, so a value
 * that lands there stays there forever.
 */
final class ChangeSet
{
    public const string REDACTED = '[redacted]';

    /**
     * @param  array<string, mixed>  $before  state before the change ([] when the record is new)
     * @param  array<string, mixed>  $after  state after the change ([] when the record is gone)
     * @param  list<string>  $sensitive  fields whose values must never be logged
     * @param  list<string>  $ignored  fields that are not part of the change (timestamps, ids)
     * @return array<string, array{from: string|int|float|bool|null, to: string|int|float|bool|null}>
     */
    public static function between(array $before, array $after, array $sensitive = [], array $ignored = []): array
    {
        $fields = array_values(array_diff(
            array_unique([...array_keys($before), ...array_keys($after)]),
            $ignored,
        ));
        sort($fields);

        $changes = [];

        foreach ($fields as $field) {
            $from = self::normalize($before[$field] ?? null);
            $to = self::normalize($after[$field] ?? null);

            if ($from === $to) {
                continue;
            }

            $changes[$field] = in_array($field, $sensitive, true)
                ? ['from' => $from === null ? null : self::REDACTED, 'to' => $to === null ? null : self::REDACTED]
                : ['from' => $from, 'to' => $to];
        }

        return $changes;
    }

    /**
     * One comparable, JSON-safe representation per value: enums by their value, dates as
     * ISO dates, empty strings as null (a cleared field and a never-filled one are the same).
     */
    private static function normalize(mixed $value): string|int|float|bool|null
    {
        return match (true) {
            $value === null, $value === '' => null,
            $value instanceof BackedEnum => $value->value,
            $value instanceof DateTimeInterface => $value->format('H:i:s') === '00:00:00'
                ? $value->format('Y-m-d')
                : $value->format(DateTimeInterface::ATOM),
            is_scalar($value) => $value,
            $value instanceof Stringable => (string) $value,
            default => (string) json_encode($value, JSON_UNESCAPED_UNICODE),
        };
    }
}
