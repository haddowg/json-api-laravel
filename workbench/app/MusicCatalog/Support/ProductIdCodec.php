<?php

declare(strict_types=1);

namespace Workbench\App\MusicCatalog\Support;

use haddowg\JsonApi\Resource\Field\IdEncoderInterface;

/**
 * A small, dependency-free, reversible id codec — the framework-neutral core interface,
 * ported verbatim from the Symfony example's {@see ProductIdCodec}. It proves the JSON:API
 * `id` a client sees can be the wire form of a distinct storage key: the auto-increment
 * integer primary key of the `products` type is never exposed; clients see an opaque
 * `prod-…` token.
 *
 * The transform XORs each byte of the decimal storage key with a fixed key stream and
 * hex-encodes the result behind a `prod-` prefix, so it is fully reversible without a
 * dependency. {@see decode()} returns `null` for anything not a well-formed token, which
 * the reference layer treats as a `404` (read) / bad linkage target (write).
 */
final class ProductIdCodec implements IdEncoderInterface
{
    private const string PREFIX = 'prod-';

    private const string KEY = "\x4a\x37\x9e\x21";

    public function encode(mixed $storageKey): string
    {
        $key = \is_scalar($storageKey) ? (string) $storageKey : '';

        return self::PREFIX . \bin2hex(self::xor($key));
    }

    public function decode(string $wireId): mixed
    {
        if (!\str_starts_with($wireId, self::PREFIX)) {
            return null;
        }

        $hex = \substr($wireId, \strlen(self::PREFIX));
        if ($hex === '' || (\strlen($hex) % 2) !== 0 || \preg_match('/^[0-9a-f]+$/', $hex) !== 1) {
            return null;
        }

        $bytes = \hex2bin($hex);
        if ($bytes === false) {
            return null;
        }

        $decoded = self::xor($bytes);

        return \preg_match('/^[0-9]+$/', $decoded) === 1 ? $decoded : null;
    }

    private static function xor(string $value): string
    {
        $key = self::KEY;
        $keyLength = \strlen($key);
        $out = '';
        for ($i = 0, $length = \strlen($value); $i < $length; $i++) {
            $out .= $value[$i] ^ $key[$i % $keyLength];
        }

        return $out;
    }
}
