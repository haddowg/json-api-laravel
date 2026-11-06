<?php

declare(strict_types=1);

namespace haddowg\JsonApiLaravel\Tests\Fixtures\Deprecation;

use haddowg\JsonApi\Resource\AbstractResource;
use haddowg\JsonApi\Resource\Field\BelongsTo;
use haddowg\JsonApi\Resource\Field\Id;
use haddowg\JsonApi\Resource\Field\Str;
use haddowg\JsonApiLaravel\Attribute\AsJsonApiResource;

/**
 * Deprecates `delete` and the related `writer` endpoint by name, and the `subtitle`
 * attribute and `writer` relation as members. The type itself is current.
 *
 * @internal
 */
#[AsJsonApiResource(deprecatedOperations: ['delete', 'fetchRelated.writer'])]
final class ArticleResource extends AbstractResource
{
    public static string $type = 'articles';

    public function fields(): array
    {
        return [
            Id::make(),
            Str::make('title'),
            Str::make('subtitle')->deprecated(),
            BelongsTo::make('writer', 'writers')->deprecated(),
        ];
    }
}
