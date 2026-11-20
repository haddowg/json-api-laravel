<?php

declare(strict_types=1);

namespace Workbench\App\NestedFieldsets;

use haddowg\JsonApi\Request\JsonApiRequestInterface;
use haddowg\JsonApi\Resource\AbstractResource;
use haddowg\JsonApi\Resource\Field\ArrayList;
use haddowg\JsonApi\Resource\Field\Decimal;
use haddowg\JsonApi\Resource\Field\Id;
use haddowg\JsonApi\Resource\Field\Integer;
use haddowg\JsonApi\Resource\Field\Obj;
use haddowg\JsonApi\Resource\Field\Str;
use haddowg\JsonApiLaravel\Attribute\AsJsonApiResource;

/**
 * The related type of the nested fieldsets witness, whose `stats` keys a predicate admits
 * to a nested fieldset, whose `office` holds a nested `geo` declaring one in turn and whose `exhibits`
 * list selects keys of every item, so a nested fieldset reaches `included` and the related
 * endpoint.
 */
#[AsJsonApiResource]
final class CuratorResource extends AbstractResource
{
    public static string $type = 'curators';

    public function fields(): array
    {
        return [
            Id::make(),
            Str::make('name'),
            Obj::make('stats')->nullable()
                ->additionalProperties(Integer::make('*'))
                ->nestedFieldset(static fn(string $key, JsonApiRequestInterface $request): bool => $key !== 'secret'),
            Obj::make('office')->nullable()
                ->fields(Str::make('city'), Obj::make('geo')->fields(Decimal::make('lat'), Decimal::make('lng'))->nestedFieldset(pattern: 'lat|lng'))
                ->nestedFieldset(pattern: '[a-z]+'),
            ArrayList::make('exhibits')->nullable()
                ->of(Obj::make('exhibit')->fields(Str::make('title'), Integer::make('year'))->nestedFieldset(pattern: '[a-z]+')),
        ];
    }
}
