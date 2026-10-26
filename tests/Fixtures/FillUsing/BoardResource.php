<?php

declare(strict_types=1);

namespace haddowg\JsonApiLaravel\Tests\Fixtures\FillUsing;

use haddowg\JsonApi\Resource\AbstractResource;
use haddowg\JsonApi\Resource\Field\Id;
use haddowg\JsonApi\Resource\Field\Str;

final class BoardResource extends AbstractResource
{
    public static string $type = 'boards';

    public function fields(): array
    {
        return [
            Id::make(),
            Str::make('name'),
        ];
    }

    /**
     * Names the type of a pin target by its model, so the polymorphic `target` resolves it.
     */
    public function getType(mixed $object): string
    {
        return $object instanceof Card ? 'cards' : 'boards';
    }
}
