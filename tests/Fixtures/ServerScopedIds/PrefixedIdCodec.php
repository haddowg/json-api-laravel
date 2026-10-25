<?php

declare(strict_types=1);

namespace haddowg\JsonApiLaravel\Tests\Fixtures\ServerScopedIds;

use haddowg\JsonApi\Resource\Field\IdEncoderInterface;

/**
 * Wire ids are the storage key behind a `p-` prefix.
 */
final class PrefixedIdCodec implements IdEncoderInterface
{
    public function encode(mixed $storageKey): string
    {
        return 'p-' . (\is_scalar($storageKey) ? (string) $storageKey : '');
    }

    public function decode(string $wireId): mixed
    {
        return \preg_match('/^p-([0-9]+)$/', $wireId, $match) === 1 ? $match[1] : null;
    }
}
