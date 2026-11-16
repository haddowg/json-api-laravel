<?php

declare(strict_types=1);

namespace haddowg\JsonApiLaravel\Tests\Fixtures\Inbox;

use haddowg\JsonApi\Resource\AbstractResource;
use haddowg\JsonApi\Resource\Field\Id;
use haddowg\JsonApi\Resource\Field\MorphTo;
use haddowg\JsonApi\Resource\Filter\WhereMorph;

/**
 * The same items with the subject identified only by a closure, which the Eloquent provider
 * filters through the model's own morphTo relation and its morph map.
 */
final class NativeSubjectItemResource extends AbstractResource
{
    public static string $type = 'inbNativeItems';

    public function fields(): array
    {
        return [
            Id::make()->numeric(),
            MorphTo::make('subject', ['inbRfps', 'inbCampaigns'])
                ->identifiedBy(static fn(mixed $item): ?array => $item instanceof InboxItem && $item->subject_type !== null && $item->subject_id !== null
                    ? [InboxItemResource::TYPE_MAP[$item->subject_type], $item->subject_id]
                    : null)
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
        return $object instanceof InboxItem ? static::$type : '';
    }
}
