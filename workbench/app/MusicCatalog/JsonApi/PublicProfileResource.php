<?php

declare(strict_types=1);

namespace Workbench\App\MusicCatalog\JsonApi;

use haddowg\JsonApi\Resource\AbstractResource;
use haddowg\JsonApi\Resource\Field\Id;
use haddowg\JsonApi\Resource\Field\Str;
use haddowg\JsonApiLaravel\Attribute\AsJsonApiResource;
use haddowg\JsonApiLaravel\Operation\Operation;
use Workbench\App\MusicCatalog\Domain\User as UserDomain;
use Workbench\App\MusicCatalog\Models\User as UserModel;

/**
 * The `public-profiles` resource type (music-catalog domain) — the one-entity-two-types
 * witness: a curated, read-only, default-server view of the SAME User row the admin-only
 * `users` type exposes. Only `displayName` is declared, so no sparse fieldset, include, or
 * relationship can resurface the private columns; the curation is the field inventory.
 */
#[AsJsonApiResource(
    operations: [Operation::FetchCollection, Operation::FetchOne],
    tags: ['Library'],
)]
final class PublicProfileResource extends AbstractResource
{
    public static string $type = 'public-profiles';

    public function fields(): array
    {
        return [
            Id::make(),
            Str::make('displayName')->storedAs('display_name')->sortable(),
        ];
    }

    /**
     * Object-aware so this resource can be a relation target resolved by class: a real
     * User (model or POPO) is a `public-profiles` here.
     */
    public function getType(mixed $object): string
    {
        return $object instanceof UserModel || $object instanceof UserDomain ? 'public-profiles' : '';
    }
}
