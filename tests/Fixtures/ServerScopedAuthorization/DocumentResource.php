<?php

declare(strict_types=1);

namespace haddowg\JsonApiLaravel\Tests\Fixtures\ServerScopedAuthorization;

use haddowg\JsonApi\Resource\AbstractResource;
use haddowg\JsonApi\Resource\Field\HasMany;
use haddowg\JsonApi\Resource\Field\Id;
use haddowg\JsonApi\Resource\Field\Str;
use haddowg\JsonApiLaravel\Attribute\AsJsonApiResource;

/**
 * `documents` on the `default` server, under its own dedicated policy.
 */
#[AsJsonApiResource(policy: PublicDocumentPolicy::class)]
final class DocumentResource extends AbstractResource
{
    public static string $type = 'documents';

    public function fields(): array
    {
        return [
            Id::make(),
            Str::make('title'),
            HasMany::make('notes', 'notes'),
            HasMany::make('revisions', 'notes'),
        ];
    }
}
