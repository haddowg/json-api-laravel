<?php

declare(strict_types=1);

namespace haddowg\JsonApiLaravel\Tests\Fixtures\ThroughFilters;

use haddowg\JsonApi\Resource\Field\IdEncoderInterface;

/**
 * Serves a template's integer key as `tpl-<key>`.
 */
final class TemplateIdCodec implements IdEncoderInterface
{
    public function encode(mixed $storageKey): string
    {
        return 'tpl-' . (\is_scalar($storageKey) ? (string) $storageKey : '');
    }

    public function decode(string $wireId): mixed
    {
        return \preg_match('/^tpl-([0-9]+)$/', $wireId, $match) === 1 ? $match[1] : null;
    }
}
