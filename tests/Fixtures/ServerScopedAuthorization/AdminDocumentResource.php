<?php

declare(strict_types=1);

namespace haddowg\JsonApiLaravel\Tests\Fixtures\ServerScopedAuthorization;

use haddowg\JsonApi\Resource\AbstractResource;
use haddowg\JsonApi\Resource\Field\HasMany;
use haddowg\JsonApi\Resource\Field\Id;
use haddowg\JsonApi\Resource\Field\Str;
use haddowg\JsonApiLaravel\Attribute\AsJsonApiResource;
use haddowg\JsonApiLaravel\Operation\Operation;

/**
 * The same `documents` type on the `admin` server, through a different resource class with
 * a different policy, `update` renamed to `edit`, the `delete` check disabled, private
 * caching, a deprecation signal and its own tag.
 */
#[AsJsonApiResource(
    server: 'admin',
    policy: AdminDocumentPolicy::class,
    abilities: [Operation::Update->value => 'edit', Operation::Delete->value => false],
    tags: ['Admin documents'],
    cacheHeaders: ['max_age' => 30, 'public' => false],
    deprecation: true,
)]
final class AdminDocumentResource extends AbstractResource
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
