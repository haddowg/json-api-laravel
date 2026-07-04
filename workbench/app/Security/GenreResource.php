<?php

declare(strict_types=1);

namespace Workbench\App\Security;

use haddowg\JsonApi\Resource\AbstractResource;
use haddowg\JsonApi\Resource\Field\Id;
use haddowg\JsonApi\Resource\Field\Str;
use haddowg\JsonApiLaravel\Attribute\AsJsonApiResource;

/**
 * The **policy-less** `genres` type for the authorization conformance suite, served on
 * the unguarded `default` server — the inertness witness (PLAN decision 7): it declares
 * no `policy:` and no `abilities`, no `Gate::policy()` is registered for it, so the
 * {@see \haddowg\JsonApiLaravel\Authorization\Authorizer} performs NO check and an
 * unauthenticated client may read and write it freely (proving the package adds no
 * authorization an application did not ask for).
 */
#[AsJsonApiResource]
final class GenreResource extends AbstractResource
{
    public static string $type = 'genres';

    public function fields(): array
    {
        return [
            Id::make()->requireClientId(),
            Str::make('name')->required(),
        ];
    }
}
