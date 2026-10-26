<?php

declare(strict_types=1);

namespace Workbench\App\ActionSafety;

use haddowg\JsonApi\Resource\AbstractResource;
use haddowg\JsonApi\Resource\Field\Id;
use haddowg\JsonApi\Resource\Field\Str;
use haddowg\JsonApiLaravel\Attribute\AsJsonApiResource;

/**
 * The mount type for the action safety witnesses. Its type-wide extensions let a test see an
 * action's own set merge over them.
 */
#[AsJsonApiResource(extensions: ['x-feature' => 'lamps', 'x-stability' => 'beta'])]
final class LampResource extends AbstractResource
{
    public static string $type = 'lamps';

    public function fields(): array
    {
        return [
            Id::make(),
            Str::make('name'),
        ];
    }
}
