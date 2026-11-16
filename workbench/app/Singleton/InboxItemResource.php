<?php

declare(strict_types=1);

namespace Workbench\App\Singleton;

use haddowg\JsonApi\Resource\AbstractResource;
use haddowg\JsonApi\Resource\Field\BelongsTo;
use haddowg\JsonApi\Resource\Field\Id;
use haddowg\JsonApi\Resource\Field\Str;
use haddowg\JsonApiLaravel\Attribute\AsJsonApiResource;

#[AsJsonApiResource]
final class InboxItemResource extends AbstractResource
{
    public static string $type = 'inboxItems';

    public function fields(): array
    {
        return [
            Id::make(),
            Str::make('subject'),
            BelongsTo::make('inbox', 'inboxes'),
        ];
    }
}
