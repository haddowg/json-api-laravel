<?php

declare(strict_types=1);

namespace Workbench\App\LinkRows;

use haddowg\JsonApi\Resource\AbstractResource;
use haddowg\JsonApi\Resource\Field\Id;
use haddowg\JsonApi\Resource\Field\Str;

final class PhotoResource extends AbstractResource
{
    public static string $type = 'photos';

    public function fields(): array
    {
        return [Id::make(), Str::make('caption')];
    }
}
