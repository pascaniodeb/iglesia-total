<?php

namespace App\Modules\Integrity\Infrastructure\Anchoring;

use InvalidArgumentException;

/**
 * RFC 3161 request (TimeStampReq, DER) for a SHA-256 digest: version 1, message imprint, random
 * nonce (stops an old reply being reused) and a request for the signer's certificate.
 */
final class TimestampQuery
{
    /** AlgorithmIdentifier of SHA-256 (OID 2.16.840.1.101.3.4.2.1, NULL parameters). */
    private const string SHA256 = "\x30\x0d\x06\x09\x60\x86\x48\x01\x65\x03\x04\x02\x01\x05\x00";

    public static function build(string $digest, string $nonce): string
    {
        if (strlen($digest) !== 32 || strlen($nonce) !== 8) {
            throw new InvalidArgumentException('The RFC 3161 request carries a SHA-256 digest and an 8-byte nonce.');
        }

        $imprint = self::tlv("\x30", self::SHA256.self::tlv("\x04", $digest));
        // Positive INTEGER: if the first bit is set a zero is prepended.
        $nonceInteger = self::tlv("\x02", (ord($nonce[0]) & 0x80 ? "\x00" : '').$nonce);

        return self::tlv("\x30", "\x02\x01\x01".$imprint.$nonceInteger."\x01\x01\xff");
    }

    /**
     * Tag, length (DER: short up to 127, long with 1 or 2 bytes) and value.
     */
    private static function tlv(string $tag, string $value): string
    {
        $length = strlen($value);

        $encoded = match (true) {
            $length < 0x80 => chr($length),
            $length <= 0xFF => "\x81".chr($length),
            $length <= 0xFFFF => "\x82".pack('n', $length),
            default => throw new InvalidArgumentException('DER value too long.'),
        };

        return $tag.$encoded.$value;
    }
}
