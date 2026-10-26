<?php

declare(strict_types=1);

namespace Workbench\App\Examples;

use haddowg\JsonApi\Resource\AbstractResource;
use haddowg\JsonApi\Resource\Field\Id;
use haddowg\JsonApi\Resource\Field\Str;
use haddowg\JsonApiLaravel\Attribute\AsJsonApiResource;

#[AsJsonApiResource(readOnly: true)]
final class VendorResource extends AbstractResource
{
    public static string $type = 'vendors';

    public function fields(): array
    {
        return [
            Id::make()->example('3'),
            Str::make('name')->example('Acme'),
        ];
    }
}
