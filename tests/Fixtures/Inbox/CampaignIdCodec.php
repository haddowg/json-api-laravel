<?php

declare(strict_types=1);

namespace haddowg\JsonApiLaravel\Tests\Fixtures\Inbox;

use haddowg\JsonApi\Resource\Field\IdEncoderInterface;

/**
 * Serves a campaign's integer key as `cmp-<key>`.
 */
final class CampaignIdCodec implements IdEncoderInterface
{
    public function encode(mixed $storageKey): string
    {
        return 'cmp-' . (\is_scalar($storageKey) ? (string) $storageKey : '');
    }

    public function decode(string $wireId): mixed
    {
        return \preg_match('/^cmp-([0-9]+)$/', $wireId, $match) === 1 ? $match[1] : null;
    }
}
