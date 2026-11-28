<?php

namespace App\Modules\Integrity\Infrastructure\Anchoring;

use InvalidArgumentException;

/**
 * OpenTimestamps proof: a tree of operations (append, prepend, sha256…) leading from a message to
 * one or more attestations (pending at a calendar, or a Bitcoin block). Official binary format
 * (python-opentimestamps): used to ask the calendar for the upgrade, graft it and export an .ots
 * file the official tool (`ots verify`) accepts.
 *
 * Nodo: ['msg' => string, 'attestations' => list<array{tag: string, payload: string}>,
 *        'ops' => list<array{tag: string, arg: string|null, child: array}>]
 */
final class OtsTimestamp
{
    public const string FILE_MAGIC = "\x00OpenTimestamps\x00\x00Proof\x00\xbf\x89\xe2\xe8\x84\xe8\x92\x94";

    public const string PENDING = "\x83\xdf\xe3\x0d\x2e\xf9\x0c\x8e";

    public const string BITCOIN = "\x05\x88\x96\x0d\x73\xd7\x19\x01";

    private const int MAX_DEPTH = 256;

    private const int MAX_ARG = 4096;

    /** @var array<string, string> one-argument operations */
    private const array BINARY_OPS = ["\xf0" => 'append', "\xf1" => 'prepend'];

    /** @var array<string, string> no-argument operations */
    private const array UNARY_OPS = ["\x08" => 'sha256', "\x02" => 'sha1', "\x03" => 'ripemd160', "\xf2" => 'reverse', "\xf3" => 'hexlify'];

    /** Reading state (only in the instance that is parsing). */
    private string $bytes = '';

    private int $offset = 0;

    /**
     * @param  array<string, mixed>  $root
     */
    private function __construct(public readonly array $root) {}

    /**
     * Reads a proof that starts from the given message (the digest or a commitment).
     */
    public static function parse(string $bytes, string $msg): self
    {
        $reader = new self([]);
        $reader->bytes = $bytes;
        $root = $reader->readNode($msg, 0);

        if ($reader->offset !== strlen($bytes)) {
            throw new InvalidArgumentException('OpenTimestamps proof with leftover bytes.');
        }

        return new self($root);
    }

    public function serialize(): string
    {
        return self::writeNode($this->root);
    }

    /**
     * Detached .ots file: header, sha256 of the file and the proof from that digest.
     */
    public function detachedFile(): string
    {
        if (strlen($this->root['msg']) !== 32) {
            throw new InvalidArgumentException('The .ots file starts from a SHA-256 digest.');
        }

        return self::FILE_MAGIC."\x01\x08".$this->root['msg'].$this->serialize();
    }

    /**
     * Pending attestations: the commitment to query and the URL of the calendar that holds it.
     *
     * @return list<array{commitment: string, uri: string}>
     */
    public function pending(): array
    {
        return array_map(
            fn (array $found) => ['commitment' => $found['msg'], 'uri' => self::calendarUri($found['payload'])],
            $this->attestations(self::PENDING),
        );
    }

    /**
     * The calendar URL inside the payload of a pending attestation. On the wire the payload is
     * itself a length-prefixed string (the official format), so reading it as the URL would leave a
     * stray length byte at the front and the calendar would never be queried.
     */
    private static function calendarUri(string $payload): string
    {
        $offset = 0;
        $length = self::readVarUint($payload, $offset);

        if ($offset + $length !== strlen($payload)) {
            throw new InvalidArgumentException('A pending attestation carries a malformed calendar URL.');
        }

        return substr($payload, $offset, $length);
    }

    /**
     * Bitcoin attestations: the commitment (the block's Merkle root) and the height.
     *
     * @return list<array{commitment: string, height: int}>
     */
    public function bitcoin(): array
    {
        return array_map(function (array $found): array {
            $offset = 0;

            return ['commitment' => $found['msg'], 'height' => self::readVarUint($found['payload'], $offset)];
        }, $this->attestations(self::BITCOIN));
    }

    /**
     * Grafts a calendar's upgrade onto the commitment's node, in place of its pending
     * attestation.
     */
    public function graft(string $commitment, self $upgrade): self
    {
        if ($upgrade->root['msg'] !== $commitment) {
            throw new InvalidArgumentException('The upgrade does not belong to that commitment.');
        }

        $grafted = false;
        $root = self::graftNode($this->root, $upgrade->root, $grafted);

        if (! $grafted) {
            throw new InvalidArgumentException('The proof has no pending attestation for that commitment.');
        }

        return new self($root);
    }

    // --- Reading -----------------------------------------------------------------------------

    /**
     * @return array<string, mixed>
     */
    private function readNode(string $msg, int $depth): array
    {
        if ($depth > self::MAX_DEPTH) {
            throw new InvalidArgumentException('OpenTimestamps proof too deep.');
        }

        $node = ['msg' => $msg, 'attestations' => [], 'ops' => []];
        $tag = $this->readBytes(1);

        while ($tag === "\xff") {
            $node = $this->readEntry($node, $this->readBytes(1), $depth);
            $tag = $this->readBytes(1);
        }

        return $this->readEntry($node, $tag, $depth);
    }

    /**
     * @param  array<string, mixed>  $node
     * @return array<string, mixed>
     */
    private function readEntry(array $node, string $tag, int $depth): array
    {
        if ($tag === "\x00") {
            $node['attestations'][] = ['tag' => $this->readBytes(8), 'payload' => $this->readVarBytes()];

            return $node;
        }

        $arg = isset(self::BINARY_OPS[$tag]) ? $this->readVarBytes() : null;

        if ($arg === null && ! isset(self::UNARY_OPS[$tag])) {
            throw new InvalidArgumentException('Unknown OpenTimestamps operation: 0x'.bin2hex($tag));
        }

        $node['ops'][] = ['tag' => $tag, 'arg' => $arg, 'child' => $this->readNode(self::apply($tag, $arg, $node['msg']), $depth + 1)];

        return $node;
    }

    private function readBytes(int $length): string
    {
        if ($this->offset + $length > strlen($this->bytes)) {
            throw new InvalidArgumentException('OpenTimestamps proof truncated.');
        }

        $chunk = substr($this->bytes, $this->offset, $length);
        $this->offset += $length;

        return $chunk;
    }

    private function readVarBytes(): string
    {
        $length = self::readVarUint($this->bytes, $this->offset);

        if ($length < 1 || $length > self::MAX_ARG) {
            throw new InvalidArgumentException('OpenTimestamps argument out of range.');
        }

        return $this->readBytes($length);
    }

    /**
     * Variable-length integer (LEB128: 7 bits per byte, the high bit says more follows).
     */
    private static function readVarUint(string $bytes, int &$offset): int
    {
        $value = 0;
        $shift = 0;

        do {
            if ($offset >= strlen($bytes) || $shift > 56) {
                throw new InvalidArgumentException('Invalid OpenTimestamps integer.');
            }

            $byte = ord($bytes[$offset++]);
            $value |= ($byte & 0x7F) << $shift;
            $shift += 7;
        } while ($byte & 0x80);

        return $value;
    }

    // --- Writing ---------------------------------------------------------------------------

    /**
     * @param  array<string, mixed>  $node
     */
    private static function writeNode(array $node): string
    {
        $entries = [
            ...array_map(fn (array $a) => "\x00".$a['tag'].self::varBytes($a['payload']), $node['attestations']),
            ...array_map(fn (array $op) => $op['tag'].($op['arg'] === null ? '' : self::varBytes($op['arg'])).self::writeNode($op['child']), $node['ops']),
        ];

        if ($entries === []) {
            throw new InvalidArgumentException('An OpenTimestamps proof cannot have empty nodes.');
        }

        $last = array_pop($entries);

        return implode('', array_map(fn (string $entry) => "\xff".$entry, $entries)).$last;
    }

    private static function varBytes(string $bytes): string
    {
        return self::varUint(strlen($bytes)).$bytes;
    }

    private static function varUint(int $value): string
    {
        $out = '';

        do {
            $byte = $value & 0x7F;
            $value >>= 7;
            $out .= chr($value > 0 ? $byte | 0x80 : $byte);
        } while ($value > 0);

        return $out;
    }

    // --- Tree -------------------------------------------------------------------------------

    private static function apply(string $tag, ?string $arg, string $msg): string
    {
        $result = match (self::BINARY_OPS[$tag] ?? self::UNARY_OPS[$tag]) {
            'append' => $msg.$arg,
            'prepend' => $arg.$msg,
            'sha256' => hash('sha256', $msg, true),
            'sha1' => hash('sha1', $msg, true),
            'ripemd160' => hash('ripemd160', $msg, true),
            'reverse' => strrev($msg),
            'hexlify' => bin2hex($msg),
        };

        if (strlen($result) > self::MAX_ARG) {
            throw new InvalidArgumentException('OpenTimestamps result too long.');
        }

        return $result;
    }

    /**
     * @return list<array{msg: string, payload: string}>
     */
    private function attestations(string $tag): array
    {
        $found = [];
        $walk = function (array $node) use (&$walk, &$found, $tag): void {
            foreach ($node['attestations'] as $attestation) {
                if ($attestation['tag'] === $tag) {
                    $found[] = ['msg' => $node['msg'], 'payload' => $attestation['payload']];
                }
            }

            foreach ($node['ops'] as $op) {
                $walk($op['child']);
            }
        };
        $walk($this->root);

        return $found;
    }

    /**
     * @param  array<string, mixed>  $node
     * @param  array<string, mixed>  $upgrade
     * @return array<string, mixed>
     */
    private static function graftNode(array $node, array $upgrade, bool &$grafted): array
    {
        if (! $grafted && $node['msg'] === $upgrade['msg']) {
            $pending = array_filter($node['attestations'], fn (array $a) => $a['tag'] === self::PENDING);

            if ($pending !== []) {
                $grafted = true;
                $node['attestations'] = [
                    ...array_values(array_filter($node['attestations'], fn (array $a) => $a['tag'] !== self::PENDING)),
                    ...$upgrade['attestations'],
                ];
                $node['ops'] = [...$node['ops'], ...$upgrade['ops']];

                return $node;
            }
        }

        foreach ($node['ops'] as $i => $op) {
            $node['ops'][$i]['child'] = self::graftNode($op['child'], $upgrade, $grafted);
        }

        return $node;
    }
}
