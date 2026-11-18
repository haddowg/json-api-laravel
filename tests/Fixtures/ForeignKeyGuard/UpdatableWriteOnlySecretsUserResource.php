<?php

declare(strict_types=1);

namespace haddowg\JsonApiLaravel\Tests\Fixtures\ForeignKeyGuard;

use haddowg\JsonApi\Resource\AbstractResource;
use haddowg\JsonApi\Resource\Field\HasMany;
use haddowg\JsonApi\Resource\Field\Id;
use haddowg\JsonApiLaravel\Attribute\AsJsonApiResource;
use haddowg\JsonApiLaravel\Operation\Operation;

/**
 * A provider-backed user that takes an update, whose write-only secrets, a type a global scope
 * narrows, name no foreign key, so replacing them reads the current members.
 */
#[AsJsonApiResource(operations: [Operation::FetchOne, Operation::Update])]
final class UpdatableWriteOnlySecretsUserResource extends AbstractResource
{
    public static string $type = 'fk-users';

    public function fields(): array
    {
        return [Id::make(), HasMany::make('secrets', 'fk-secrets')->writeOnly()];
    }
}
