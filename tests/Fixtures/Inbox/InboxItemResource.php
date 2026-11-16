<?php

declare(strict_types=1);

namespace haddowg\JsonApiLaravel\Tests\Fixtures\Inbox;

use haddowg\JsonApi\Resource\AbstractResource;
use haddowg\JsonApi\Resource\Field\DateTime;
use haddowg\JsonApi\Resource\Field\Id;
use haddowg\JsonApi\Resource\Field\MorphTo;
use haddowg\JsonApi\Resource\Field\MorphToMany;
use haddowg\JsonApi\Resource\Field\Str;
use haddowg\JsonApi\Resource\Filter\WhereMorph;
use haddowg\JsonApi\Resource\Filter\WhereSet;

/**
 * Inbox items about an rfp or a campaign. The subject is linkage only, with no related
 * endpoint and no include, and archived items are left out unless a client asks. Both stores
 * keep the subject and the links under the morph map's aliases.
 */
final class InboxItemResource extends AbstractResource
{
    public static string $type = 'inbItems';

    public const array TYPE_MAP = ['rfp' => 'inbRfps', 'campaign' => 'inbCampaigns'];

    public function fields(): array
    {
        return [
            Id::make()->numeric(),
            Str::make('title'),
            DateTime::make('readTs')->storedAs('read_ts')->nullable(),
            DateTime::make('archivedTs')->storedAs('archived_ts')->nullable(),
            MorphTo::make('subject', ['inbRfps', 'inbCampaigns'])
                ->identifiedByColumns(type: 'subject_type', id: 'subject_id', typeMap: self::TYPE_MAP)
                ->cannotBeIncluded()
                ->withoutRelatedEndpoint(),
            MorphToMany::make('links', ['inbRfps', 'inbCampaigns'])
                ->through(InboxLink::class)
                ->identifiedByRows(owner: 'item', type: 'linked_type', id: 'linked_id', typeMap: self::TYPE_MAP)
                ->cannotBeIncluded()
                ->withoutRelatedEndpoint(),
        ];
    }

    public function filters(): array
    {
        return [
            WhereSet::make('read', 'read_ts'),
            WhereSet::make('archived', 'archived_ts')->default(false)->describedAs('Whether the item is archived. Archived items are left out unless asked for.'),
            WhereMorph::make('subject')->describedAs('Items about this resource.')->example(['type' => 'inbRfps', 'id' => ['812']]),
            WhereMorph::make('linked', 'links'),
        ];
    }

    public function getType(mixed $object): string
    {
        return $object instanceof InboxItem || $object instanceof InboxItemRecord ? static::$type : '';
    }
}
