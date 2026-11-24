<?php

declare(strict_types=1);

namespace haddowg\JsonApiLaravel\Tests\Fixtures\IdKinds;

use haddowg\JsonApi\Resource\AbstractResource;
use haddowg\JsonApi\Resource\Field\BelongsToMany;
use haddowg\JsonApi\Resource\Field\Str;
use haddowg\JsonApi\Resource\Filter\Contains;

final class IkLabelResource extends AbstractResource
{
    public static string $type = 'ikLabels';

    public function fields(): array
    {
        return [
            IdKinds::id(self::$type),
            Str::make('name')->sortable(),
            BelongsToMany::make('books', 'ikBooks')->countable(),
        ];
    }

    public function filters(): array
    {
        return [Contains::make('name')];
    }

    public function getType(mixed $object): string
    {
        return $object instanceof IkLabel || $object instanceof IkLabelRecord ? self::$type : '';
    }
}
