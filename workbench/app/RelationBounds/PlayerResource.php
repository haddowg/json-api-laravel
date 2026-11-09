<?php

declare(strict_types=1);

namespace Workbench\App\RelationBounds;

use haddowg\JsonApi\Resource\AbstractResource;
use haddowg\JsonApi\Resource\Field\Id;
use haddowg\JsonApi\Resource\Field\Str;

final class PlayerResource extends AbstractResource
{
    public static string $type = 'players';

    public function fields(): array
    {
        return [
            Id::make(),
            Str::make('name'),
        ];
    }
}
