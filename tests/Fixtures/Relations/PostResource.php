<?php

declare(strict_types=1);

namespace haddowg\JsonApiLaravel\Tests\Fixtures\Relations;

use haddowg\JsonApi\Resource\AbstractResource;
use haddowg\JsonApi\Resource\Field\BelongsTo;
use haddowg\JsonApi\Resource\Field\BelongsToMany;
use haddowg\JsonApi\Resource\Field\Id;
use haddowg\JsonApi\Resource\Field\MorphTo;
use haddowg\JsonApi\Resource\Field\MorphToMany;
use haddowg\JsonApi\Resource\Field\Str;
use haddowg\JsonApiLaravel\Attribute\AsJsonApiResource;

/**
 * The `posts` resource — the parent of every 3a relation cardinality exercised in
 * isolation from the shared music catalog (so it needs no Eloquent morph map / pivot
 * table): a monomorphic to-one (`author`), a `belongsToMany` to-many (`tags`), a
 * **polymorphic** to-one (`feature` → an author OR a tag), and a **polymorphic** to-many
 * (`related` → a mixed list of authors + tags). The include batcher skips the polymorphic
 * relations (they render lazily, per-object), so this witnesses both the endpoint-side
 * `PolymorphicSerializer` resolution and the lazy-include path.
 */
#[AsJsonApiResource(readOnly: true)]
final class PostResource extends AbstractResource
{
    public static string $type = 'posts';

    public function fields(): array
    {
        return [
            Id::make(),
            Str::make('title'),
            BelongsTo::make('author', 'authors'),
            BelongsToMany::make('tags', 'tags'),
            MorphTo::make('feature', ['authors', 'tags']),
            MorphToMany::make('related', ['authors', 'tags']),
        ];
    }
}
