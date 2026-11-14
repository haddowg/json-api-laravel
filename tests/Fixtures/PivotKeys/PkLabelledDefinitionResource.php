<?php

declare(strict_types=1);

namespace haddowg\JsonApiLaravel\Tests\Fixtures\PivotKeys;

use haddowg\JsonApi\Resource\AbstractResource;
use haddowg\JsonApi\Resource\Field\Id;
use haddowg\JsonApi\Resource\Field\Str;
use haddowg\JsonApi\Resource\Filter\Where;
use haddowg\JsonApi\Resource\Filter\WhereIdIn;

/**
 * The definitions model served again under a second type whose ids carry a `def-` prefix,
 * so an id filter must decode with the encoder of the type it runs against.
 *
 * @internal
 */
final class PkLabelledDefinitionResource extends AbstractResource
{
    public static string $type = 'labelled-definitions';

    public function fields(): array
    {
        return [
            Id::make()->encodeUsing(new PrefixedDefinitionEncoder()),
            Str::make('name')->sortable(),
        ];
    }

    public function filters(): array
    {
        return [
            WhereIdIn::make('id', 'id'),
            Where::make('idIs', 'id'),
        ];
    }
}
