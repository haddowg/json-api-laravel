<?php

declare(strict_types=1);

namespace haddowg\JsonApiLaravel\Tests\Fixtures\TypedRequestMeta;

use haddowg\JsonApi\Resource\AbstractResource;
use haddowg\JsonApi\Resource\Field\Accessor;
use haddowg\JsonApi\Resource\Field\AdditionalProperties;
use haddowg\JsonApi\Resource\Field\Id;
use haddowg\JsonApi\Resource\Field\Str;
use haddowg\JsonApi\Resource\MetaContext;

/**
 * A plain row written as a stamp, whose resource meta opens to the members an entry
 * returns. A stamp named `Clash` has that entry also return the declared `stamp` member.
 */
class TrStampResource extends AbstractResource
{
    public static string $type = 'tr-stamps';

    public function fields(): array
    {
        return [Id::make(), Str::make('name')];
    }

    public function resourceMeta(MetaContext $context): iterable
    {
        return [
            Str::make('stamp')->readOnly()->extractUsing(static fn(object $plain): string => 'declared'),
            AdditionalProperties::make()->extractUsing(
                static fn(object $plain): array => Accessor::get($plain, 'name') === 'Clash' ? ['stamp' => 'entry', 'ink' => 'blue'] : ['ink' => 'blue'],
            ),
        ];
    }
}
