<?php

declare(strict_types=1);

namespace Workbench\App\ForeignKey;

use haddowg\JsonApi\Resource\AbstractResource;
use haddowg\JsonApi\Resource\Field\ArrayList;
use haddowg\JsonApi\Resource\Field\HasMany;
use haddowg\JsonApi\Resource\Field\Id;
use haddowg\JsonApiLaravel\Attribute\AsJsonApiResource;
use haddowg\JsonApiLaravel\Operation\Operation;

/**
 * {@see FkGrantResource} with Update, which `jsonapi:optimize` refuses over its write-only
 * secrets. `lockedSecrets` can't change its members, so a replace compares the ids the
 * grant holds.
 */
#[AsJsonApiResource(operations: [Operation::FetchOne, Operation::Create, Operation::Update])]
final class FkRevisableGrantResource extends AbstractResource
{
    public static string $type = 'fk-revisable-grants';

    public function fields(): array
    {
        return [
            Id::make(),
            ArrayList::make('received')->readOnly(),
            HasMany::make('secrets', 'fk-secrets')->writeOnly(),
            HasMany::make('lockedSecrets', 'fk-secrets')->writeOnly()->cannotChangeMembership(),
        ];
    }
}
