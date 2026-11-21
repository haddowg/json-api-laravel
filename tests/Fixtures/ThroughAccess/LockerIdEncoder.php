<?php

declare(strict_types=1);

namespace haddowg\JsonApiLaravel\Tests\Fixtures\ThroughAccess;

use haddowg\JsonApi\Resource\Field\IdEncoderInterface;

/**
 * Renders a locker's key with an `L-` prefix, so its wire id differs from its stored key.
 */
final class LockerIdEncoder implements IdEncoderInterface
{
    public function encode(mixed $storageKey): string
    {
        return 'L-' . (\is_scalar($storageKey) ? (string) $storageKey : '');
    }

    public function decode(string $wireId): mixed
    {
        return \str_starts_with($wireId, 'L-') ? \substr($wireId, 2) : null;
    }
}
