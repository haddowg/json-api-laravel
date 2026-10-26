<?php

declare(strict_types=1);

namespace Workbench\App\Examples;

use haddowg\JsonApi\Resource\AbstractResource;
use haddowg\JsonApi\Resource\Field\Id;
use haddowg\JsonApi\Resource\Field\Str;
use haddowg\JsonApiLaravel\Attribute\AsJsonApiResource;

/**
 * Exposed on the `legacy` server. `colour` declares no example, so its operations get none.
 */
#[AsJsonApiResource(server: 'legacy', readOnly: true)]
final class GizmoResource extends AbstractResource
{
    public static string $type = 'gizmos';

    public function fields(): array
    {
        return [
            Id::make()->example('1'),
            Str::make('name')->example('Whirligig'),
            Str::make('colour'),
        ];
    }
}
