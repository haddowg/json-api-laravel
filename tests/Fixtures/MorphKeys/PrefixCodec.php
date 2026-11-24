<?php

declare(strict_types=1);

namespace haddowg\JsonApiLaravel\Tests\Fixtures\MorphKeys;

use haddowg\JsonApi\Resource\Field\IdEncoderInterface;

/**
 * Serves an integer key as `<prefix><key>`.
 */
final readonly class PrefixCodec implements IdEncoderInterface
{
    public function __construct(private string $prefix) {}

    public function encode(mixed $storageKey): string
    {
        return $this->prefix . (\is_scalar($storageKey) ? (string) $storageKey : '');
    }

    public function decode(string $wireId): ?string
    {
        return \preg_match('/^' . \preg_quote($this->prefix, '/') . '([0-9]+)$/', $wireId, $match) === 1 ? $match[1] : null;
    }
}
