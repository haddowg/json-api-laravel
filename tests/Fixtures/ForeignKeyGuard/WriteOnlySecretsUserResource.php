<?php

declare(strict_types=1);

namespace haddowg\JsonApiLaravel\Tests\Fixtures\ForeignKeyGuard;

use haddowg\JsonApi\Resource\AbstractResource;
use haddowg\JsonApi\Resource\Field\HasMany;
use haddowg\JsonApi\Resource\Field\Id;
use haddowg\JsonApiLaravel\Attribute\AsJsonApiResource;

/**
 * A provider-backed user that takes no update, whose secrets, a type a global scope narrows,
 * are write-only and name no foreign key.
 */
#[AsJsonApiResource(readOnly: true)]
final class WriteOnlySecretsUserResource extends AbstractResource
{
    public static string $type = 'fk-users';

    public function fields(): array
    {
        return [Id::make(), HasMany::make('secrets', 'fk-secrets')->writeOnly()];
    }
}
