<?php

declare(strict_types=1);

namespace Workbench\App\WriteTransaction;

use haddowg\JsonApi\Resource\AbstractResource;
use haddowg\JsonApi\Resource\Field\Id;
use haddowg\JsonApi\Resource\Field\Str;
use haddowg\JsonApiLaravel\Attribute\AsJsonApiResource;

#[AsJsonApiResource]
final class TxTagResource extends AbstractResource
{
    public static string $type = 'txTags';

    public function fields(): array
    {
        return [
            Id::make()->requireClientId(),
            Str::make('label'),
        ];
    }
}
