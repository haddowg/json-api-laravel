<?php

declare(strict_types=1);

namespace haddowg\JsonApiLaravel\Tests\Fixtures\FirstPage;

use haddowg\JsonApi\Resource\AbstractResource;
use haddowg\JsonApi\Resource\Field\Id;
use haddowg\JsonApi\Resource\Field\Str;

final class FpLabelResource extends AbstractResource
{
    public static string $type = 'fp-labels';

    public function getType(mixed $object): string
    {
        return $object instanceof FpLabel || $object instanceof FpLabelRecord ? static::$type : '';
    }

    public function fields(): array
    {
        return [
            Id::make(),
            Str::make('name'),
        ];
    }
}
