<?php

declare(strict_types=1);

namespace haddowg\JsonApiLaravel\Eloquent;

use haddowg\JsonApi\Resource\Field\IdEncoderInterface;

/**
 * The id encoder for a UUID key stored as its 16 raw bytes, such as a `BINARY(16)` column: the
 * model holds the bytes, so Eloquent's own lookups and relations match on them unchanged, and
 * the wire carries the canonical lowercase UUID. Attach it to the resource's id with
 * `Id::make()->uuid()->encodeUsing(new BinaryUuidEncoder())`.
 */
final readonly class BinaryUuidEncoder implements IdEncoderInterface
{
    private const string CANONICAL = '/^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/i';

    public function encode(mixed $storageKey): string
    {
        if (\is_string($storageKey) && \strlen($storageKey) === 16) {
            return \vsprintf('%s%s-%s-%s-%s-%s%s%s', \str_split(\bin2hex($storageKey), 4));
        }

        // A key already in canonical form, such as one set from a client id before a refresh.
        if (\is_string($storageKey) && \preg_match(self::CANONICAL, $storageKey) === 1) {
            return \strtolower($storageKey);
        }

        return \is_scalar($storageKey) || $storageKey instanceof \Stringable ? (string) $storageKey : '';
    }

    public function decode(string $wireId): ?string
    {
        if (\preg_match(self::CANONICAL, $wireId) !== 1) {
            return null;
        }

        $bytes = \hex2bin(\str_replace('-', '', $wireId));

        return $bytes === false ? null : $bytes;
    }
}
