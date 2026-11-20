<?php

declare(strict_types=1);

namespace haddowg\JsonApiLaravel\Tests\Fixtures\KeySelectionOverrides;

use haddowg\JsonApi\Resource\Field\ArrayList;
use haddowg\JsonApi\Resource\Field\Decimal;
use haddowg\JsonApi\Resource\Field\FieldBuilderInterface;
use haddowg\JsonApi\Resource\Field\FieldInterface;
use haddowg\JsonApi\Resource\Field\Id;
use haddowg\JsonApi\Resource\Field\Integer;
use haddowg\JsonApi\Resource\Field\Obj;
use haddowg\JsonApi\Resource\Field\Str;

/**
 * The fields of a type that renders through a serializer override: the resource declares
 * which keys a request may select, and the override renders each attribute whole.
 *
 * @internal
 */
final class BoardFields
{
    /**
     * @return list<FieldInterface|FieldBuilderInterface>
     */
    public static function fields(): array
    {
        return [
            Id::make(),
            Str::make('name'),
            Obj::make('values')->nullable()
                ->additionalProperties(Str::make('*')->nullable())
                ->selectableKeys(pattern: '[a-z][a-z0-9_]*'),
            Obj::make('address')->nullable()
                ->fields(
                    Str::make('street'),
                    Str::make('postcode'),
                    Obj::make('geo')->fields(Decimal::make('lat'), Decimal::make('lng'))->selectableKeys(pattern: '[a-z]+'),
                )
                ->selectableKeys(pattern: '[a-z]+'),
            ArrayList::make('rows')->nullable()->of(
                Obj::make('row')
                    ->fields(Str::make('label'), Obj::make('size')->fields(Integer::make('w'), Integer::make('h'))->selectableKeys(pattern: '[a-z]'))
                    ->selectableKeys(pattern: '[a-z]+'),
            ),
        ];
    }
}
