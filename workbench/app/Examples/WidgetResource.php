<?php

declare(strict_types=1);

namespace Workbench\App\Examples;

use haddowg\JsonApi\OpenApi\Metadata\OperationExample;
use haddowg\JsonApi\Resource\AbstractResource;
use haddowg\JsonApi\Resource\Field\BelongsTo;
use haddowg\JsonApi\Resource\Field\Id;
use haddowg\JsonApi\Resource\Field\Str;
use haddowg\JsonApiLaravel\Attribute\AsJsonApiResource;

/**
 * Every field declares an example, so each operation gets a generated one. The update
 * response is replaced and the fetch-one example removed.
 */
#[AsJsonApiResource(operationExamples: [
    'update' => new OperationExample(responses: [200 => ['meta' => ['saved' => true]]]),
    'fetchOne' => false,
])]
final class WidgetResource extends AbstractResource
{
    public static string $type = 'widgets';

    public function fields(): array
    {
        return [
            Id::make()->example('7'),
            Str::make('name')->example('Sprocket'),
            BelongsTo::make('vendor', 'vendors'),
        ];
    }
}
