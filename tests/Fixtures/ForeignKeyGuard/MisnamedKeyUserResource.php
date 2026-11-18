<?php

declare(strict_types=1);

namespace haddowg\JsonApiLaravel\Tests\Fixtures\ForeignKeyGuard;

use haddowg\JsonApi\Resource\AbstractResource;
use haddowg\JsonApi\Resource\Field\HasMany;
use haddowg\JsonApi\Resource\Field\Id;
use haddowg\JsonApiLaravel\Attribute\AsJsonApiResource;

/**
 * A provider-backed user whose items name a foreign key the items don't declare.
 */
#[AsJsonApiResource(readOnly: true)]
final class MisnamedKeyUserResource extends AbstractResource
{
    public static string $type = 'fk-users';

    public function fields(): array
    {
        return [Id::make(), HasMany::make('items', 'fk-items', 'missing')];
    }
}
