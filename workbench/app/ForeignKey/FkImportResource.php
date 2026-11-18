<?php

declare(strict_types=1);

namespace Workbench\App\ForeignKey;

use haddowg\JsonApi\Resource\AbstractResource;
use haddowg\JsonApi\Resource\Field\HasMany;
use haddowg\JsonApi\Resource\Field\Id;
use haddowg\JsonApiLaravel\Attribute\AsJsonApiResource;

/**
 * A related-only type no model serves, holding secrets, a scoped type, under no foreign key.
 * Only the `import` action reads documents of it.
 */
#[AsJsonApiResource(relatedOnly: true)]
final class FkImportResource extends AbstractResource
{
    public static string $type = 'fk-imports';

    public function fields(): array
    {
        return [Id::make(), HasMany::make('secrets', 'fk-secrets')];
    }
}
