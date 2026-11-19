<?php

declare(strict_types=1);

namespace haddowg\JsonApiLaravel\Tests\Fixtures\TypedRequestMeta;

use haddowg\JsonApi\Resource\AbstractResource;
use haddowg\JsonApi\Resource\Field\Accessor;
use haddowg\JsonApi\Resource\Field\AdditionalProperties;
use haddowg\JsonApi\Resource\Field\Id;
use haddowg\JsonApi\Resource\Field\Integer;
use haddowg\JsonApi\Resource\Field\Str;
use haddowg\JsonApi\Resource\MetaContext;

/**
 * Items keep any integer meta member they are written with, and render them back.
 */
final class TrItemResource extends AbstractResource
{
    public static string $type = 'tr-items';

    public function fields(): array
    {
        return [Id::make(), Str::make('name')];
    }

    public function resourceMeta(MetaContext $context): iterable
    {
        return [
            AdditionalProperties::make()->of(Integer::make('*'))
                ->extractUsing(static fn(object $item): mixed => Accessor::get($item, 'extras'))
                ->fillUsing(static function (object $item, array $members): object {
                    Accessor::set($item, 'extras', $members);

                    return $item;
                }),
        ];
    }
}
