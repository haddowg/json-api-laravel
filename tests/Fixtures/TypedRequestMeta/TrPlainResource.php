<?php

declare(strict_types=1);

namespace haddowg\JsonApiLaravel\Tests\Fixtures\TypedRequestMeta;

use haddowg\JsonApi\Request\JsonApiRequestInterface;
use haddowg\JsonApi\Resource\AbstractResource;
use haddowg\JsonApi\Resource\Field\Id;
use haddowg\JsonApi\Resource\Field\Str;

/**
 * Declares no meta at all, so its meta stays open both ways and its getMeta() is never
 * checked.
 */
class TrPlainResource extends AbstractResource
{
    public static string $type = 'tr-plains';

    public function fields(): array
    {
        return [Id::make(), Str::make('name')];
    }

    public function getMeta(mixed $object, JsonApiRequestInterface $request): array
    {
        return ['free' => true];
    }
}
