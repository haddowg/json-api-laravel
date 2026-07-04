<?php

declare(strict_types=1);

namespace haddowg\JsonApiLaravel\Tests\Fixtures\Mutations;

use haddowg\JsonApi\Resource\AbstractResource;
use haddowg\JsonApi\Resource\Field\BelongsTo;
use haddowg\JsonApi\Resource\Field\BelongsToMany;
use haddowg\JsonApi\Resource\Field\Id;
use haddowg\JsonApi\Resource\Field\MorphTo;
use haddowg\JsonApi\Resource\Field\Str;
use haddowg\JsonApiLaravel\Attribute\AsJsonApiResource;

/**
 * The **writable** `posts` resource driving the relationship-mutation feature matrix, shared
 * by both providers (its relation `column ?? name` resolves off the Eloquent {@see Post}
 * method AND the in-memory {@see PostDomain} property):
 *  - `author` — a plain owner-side to-one (PATCH replace / null-clear, the 409 type-mismatch
 *    subject, and the inverse of {@see AuthorResource}'s `posts` HasMany);
 *  - `sponsor` — a to-one whose replacement and removal are BOTH prohibited (the
 *    `FullReplacementProhibited` / `RemovalProhibited` 403s);
 *  - `moderator` — a to-one whose mutation ability is overridden to `curate` (PLAN decision 7
 *    per-relation ability override, gated by a Gate::define in the test);
 *  - `feature` — a polymorphic to-one (MorphTo → an author OR a tag);
 *  - `tags` — a plain join-table to-many (PATCH replace / POST add / DELETE remove);
 *  - `pinnedTags` — a join to-many whose replace / add / remove are all prohibited (the three
 *    to-many 403s).
 */
#[AsJsonApiResource]
final class PostResource extends AbstractResource
{
    public static string $type = 'posts';

    public function fields(): array
    {
        return [
            Id::make(),
            Str::make('title')->required(),
            BelongsTo::make('author', 'authors'),
            BelongsTo::make('sponsor', 'authors')->cannotReplace()->cannotRemove(),
            BelongsTo::make('moderator', 'authors')->security(mutate: 'curate'),
            MorphTo::make('feature', ['authors', 'tags']),
            BelongsToMany::make('tags', 'tags'),
            BelongsToMany::make('pinnedTags', 'tags')->cannotReplace()->cannotAdd()->cannotRemove(),
        ];
    }
}
