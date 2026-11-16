<?php

declare(strict_types=1);

namespace haddowg\JsonApiLaravel\Tests\Fixtures\Inbox;

use haddowg\JsonApi\Resource\AbstractResource;
use haddowg\JsonApi\Resource\Field\Id;
use haddowg\JsonApi\Resource\Field\MorphTo;
use haddowg\JsonApi\Resource\Filter\WhereMorph;

/**
 * A WhereMorph over a MorphTo identified only by a closure, on a type no Eloquent model
 * serves, so nothing can compare its stored pair.
 */
final class ClosureSubjectResource extends AbstractResource
{
    public static string $type = 'inbClosureItems';

    public function fields(): array
    {
        return [
            Id::make()->numeric(),
            MorphTo::make('subject', ['inbRfps', 'inbCampaigns'])
                ->identifiedBy(static fn(mixed $item): ?array => null)
                ->cannotBeIncluded()
                ->withoutRelatedEndpoint(),
        ];
    }

    public function filters(): array
    {
        return [WhereMorph::make('subject')];
    }

    public function getType(mixed $object): string
    {
        return $object instanceof InboxItemRecord ? static::$type : '';
    }
}
