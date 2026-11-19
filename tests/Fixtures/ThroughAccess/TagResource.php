<?php

declare(strict_types=1);

namespace haddowg\JsonApiLaravel\Tests\Fixtures\ThroughAccess;

use haddowg\JsonApi\Resource\AbstractResource;
use haddowg\JsonApi\Resource\Field\Id;
use haddowg\JsonApi\Resource\Field\Str;
use haddowg\JsonApiLaravel\Attribute\AsJsonApiResource;

/**
 * Tags, the secret ones shown to admins only.
 */
#[AsJsonApiResource(readOnly: true)]
final class TagResource extends AbstractResource
{
    public static string $type = 'tags';

    public function fields(): array
    {
        return [
            Id::make(),
            Str::make('name'),
        ];
    }
}
