<?php

declare(strict_types=1);

namespace haddowg\JsonApiLaravel\Tests\Fixtures\RelationOrderCheck;

use haddowg\JsonApi\Resource\AbstractResource;
use haddowg\JsonApi\Resource\Field\Id;
use haddowg\JsonApi\Resource\Field\Str;

/**
 * A ledger entry, sortable only by its memo.
 */
final class EntryResource extends AbstractResource
{
    public static string $type = 'entries';

    public function fields(): array
    {
        return [
            Id::make(),
            Str::make('memo')->sortable(),
        ];
    }
}
