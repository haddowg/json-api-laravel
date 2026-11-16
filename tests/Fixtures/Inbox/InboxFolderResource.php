<?php

declare(strict_types=1);

namespace haddowg\JsonApiLaravel\Tests\Fixtures\Inbox;

use haddowg\JsonApi\Resource\AbstractResource;
use haddowg\JsonApi\Resource\Field\HasMany;
use haddowg\JsonApi\Resource\Field\Id;
use haddowg\JsonApi\Resource\Field\Str;

/**
 * Folders of inbox items: `items` keeps the items' default, `everything` drops it.
 */
final class InboxFolderResource extends AbstractResource
{
    public static string $type = 'inbFolders';

    public function fields(): array
    {
        return [
            Id::make()->numeric(),
            Str::make('name'),
            HasMany::make('items', 'inbItems'),
            HasMany::make('everything', 'inbItems')->storedAs('items')->withoutFilterDefaults(),
        ];
    }
}
