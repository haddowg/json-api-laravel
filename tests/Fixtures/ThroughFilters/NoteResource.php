<?php

declare(strict_types=1);

namespace haddowg\JsonApiLaravel\Tests\Fixtures\ThroughFilters;

use haddowg\JsonApi\Resource\AbstractResource;
use haddowg\JsonApi\Resource\Field\Id;
use haddowg\JsonApi\Resource\Field\MorphTo;
use haddowg\JsonApi\Resource\Filter\WhereThroughIn;

/**
 * A note about an advertiser or a brand, filtered by its polymorphic `subject`, which has no
 * single id to compare.
 */
final class NoteResource extends AbstractResource
{
    public static string $type = 'notes';

    public function fields(): array
    {
        return [
            Id::make(),
            MorphTo::make('subject', ['advertisers', 'brands']),
        ];
    }

    public function filters(): array
    {
        return [WhereThroughIn::make('subject')];
    }
}
