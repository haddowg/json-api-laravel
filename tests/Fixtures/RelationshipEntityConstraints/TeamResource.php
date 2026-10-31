<?php

declare(strict_types=1);

namespace haddowg\JsonApiLaravel\Tests\Fixtures\RelationshipEntityConstraints;

use haddowg\JsonApi\Resource\AbstractResource;
use haddowg\JsonApi\Resource\Field\BelongsToMany;
use haddowg\JsonApi\Resource\Field\Id;
use haddowg\JsonApiLaravel\Attribute\AsJsonApiResource;

/**
 * Declares no entity constraints, so emptying its companies is allowed.
 */
#[AsJsonApiResource]
final class TeamResource extends AbstractResource
{
    public static string $type = 'teams';

    public function fields(): array
    {
        return [
            Id::make(),
            BelongsToMany::make('companies', 'companies'),
        ];
    }
}
