<?php

declare(strict_types=1);

namespace haddowg\JsonApiLaravel\Tests\Fixtures\ForeignKeyGuard;

use haddowg\JsonApi\Resource\AbstractResource;
use haddowg\JsonApi\Resource\Field\Id;
use haddowg\JsonApi\Resource\Field\Str;
use haddowg\JsonApiLaravel\Attribute\AsJsonApiResource;

/**
 * `fk-labels`, served by {@see LabelProvider}, which can't read through a foreign key.
 */
#[AsJsonApiResource(readOnly: true)]
final class FkLabelResource extends AbstractResource
{
    public static string $type = 'fk-labels';

    public function fields(): array
    {
        return [Id::make(), Str::make('owner')];
    }
}
