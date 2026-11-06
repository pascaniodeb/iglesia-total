<?php

namespace App\Modules\Integrity\Domain\Ledger;

use InvalidArgumentException;
use JsonException;
use stdClass;

/**
 * Canonical serialization and hashing of the sealed ledger: the same facts always produce the
 * same text, byte for byte, and therefore the same hash.
 *
 * Everything else depends on this. If two runs serialized the same facts differently (key
 * order, `1.0` versus `1`, a space) the hashes would differ and the chain would be declared
 * broken without anyone touching a cent. A false alarm in an integrity system is worse than no
 * system: it teaches people to ignore the alarm.
 *
 * It is a byte-exact port of the old system's `canonicalize` (src/lib/ledger/hashing.ts), so
 * the blocks it sealed can be verified here:
 *
 * - Object keys are sorted by UTF-16 code unit, as JavaScript's `<` compares strings.
 * - Strings are escaped as `JSON.stringify` does: only `"`, `\` and control characters;
 *   non-ASCII, `/` and the line separators U+2028/U+2029 are written as they are.
 * - Amounts are never numbers: they travel as the decimal text PostgreSQL returned
 *   ("1144500.00"). Only integers (counts, heights) are accepted as numbers; a float is
 *   rejected instead of being rounded.
 * - Objects are `stdClass` or associative arrays; lists are arrays with keys 0..n-1. An empty
 *   PHP array is a list (`[]`): use `new stdClass` for an empty object.
 */
final class Canonical
{
    public const string ZERO_HASH = '0000000000000000000000000000000000000000000000000000000000000000';

    private const int STRING_FLAGS = JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_LINE_TERMINATORS | JSON_THROW_ON_ERROR;

    public static function serialize(mixed $value): string
    {
        return match (true) {
            $value === null => 'null',
            is_bool($value) => $value ? 'true' : 'false',
            is_int($value) => (string) $value,
            is_string($value) => self::string($value),
            is_float($value) => throw new InvalidArgumentException("Amounts are sealed as text, not as numbers: {$value}."),
            $value instanceof stdClass => self::object(get_object_vars($value)),
            is_array($value) => array_is_list($value) ? self::list($value) : self::object($value),
            default => throw new InvalidArgumentException('Type that cannot be sealed: '.get_debug_type($value).'.'),
        };
    }

    public static function hash(mixed $value): string
    {
        return self::sha256(self::serialize($value));
    }

    public static function sha256(string $input): string
    {
        return hash('sha256', $input);
    }

    /**
     * @param  list<mixed>  $items
     */
    private static function list(array $items): string
    {
        return '['.implode(',', array_map(self::serialize(...), $items)).']';
    }

    /**
     * @param  array<array-key, mixed>  $properties
     */
    private static function object(array $properties): string
    {
        $keys = array_map(strval(...), array_keys($properties));
        usort($keys, self::compareCodeUnits(...));

        $members = array_map(
            fn (string $key): string => self::string($key).':'.self::serialize($properties[$key]),
            $keys,
        );

        return '{'.implode(',', $members).'}';
    }

    private static function string(string $value): string
    {
        try {
            return json_encode($value, self::STRING_FLAGS);
        } catch (JsonException $exception) {
            throw new InvalidArgumentException('Text that is not valid UTF-8 cannot be sealed.', previous: $exception);
        }
    }

    /**
     * JavaScript compares strings by UTF-16 code units; UTF-16BE bytes compare the same way.
     */
    private static function compareCodeUnits(string $a, string $b): int
    {
        return strcmp(
            (string) mb_convert_encoding($a, 'UTF-16BE', 'UTF-8'),
            (string) mb_convert_encoding($b, 'UTF-16BE', 'UTF-8'),
        );
    }
}
