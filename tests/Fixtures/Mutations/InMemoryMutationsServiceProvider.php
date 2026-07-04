<?php

declare(strict_types=1);

namespace haddowg\JsonApiLaravel\Tests\Fixtures\Mutations;

use haddowg\JsonApi\Resource\Field\Accessor;
use haddowg\JsonApiLaravel\DataPersister\InMemoryDataPersister;
use haddowg\JsonApiLaravel\DataProvider\InMemoryDataProvider;
use haddowg\JsonApiLaravel\Facades\JsonApi;
use Illuminate\Support\ServiceProvider;

/**
 * The in-memory witness half of the mutation fixture: it points discovery at the SAME
 * writable resources the Eloquent wiring serves and registers one {@see InMemoryDataProvider}
 * per type over a linked object graph, with writable `posts` + `authors`
 * {@see InMemoryDataPersister}s sharing each provider's store. A single `relatedResolver`
 * spans all three graphs so a linkage id resolves back to the stored related object (by the
 * linkage member's own type — polymorphic safe for the `feature` MorphTo).
 */
final class InMemoryMutationsServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        JsonApi::discover([__DIR__]);

        /** @var array<string, AuthorDomain> $authors */
        $authors = [];
        /** @var array<string, TagDomain> $tags */
        $tags = [];
        /** @var array<string, PostDomain> $posts */
        $posts = [];

        $ada = new AuthorDomain('1', 'Ada');
        $grace = new AuthorDomain('2', 'Grace');
        $authors = ['1' => $ada, '2' => $grace];

        $php = new TagDomain('1', 'php');
        $json = new TagDomain('2', 'json');
        $rust = new TagDomain('3', 'rust');
        $tags = ['1' => $php, '2' => $json, '3' => $rust];

        $hello = new PostDomain('1', 'Hello', author: $ada, feature: $json, tags: [$php, $json]);
        $world = new PostDomain('2', 'World', author: $grace, feature: $ada, tags: [$php]);
        $empty = new PostDomain('3', 'Empty');
        $posts = ['1' => $hello, '2' => $world, '3' => $empty];

        $ada->posts = [$hello];
        $grace->posts = [$world];

        $resolver = static function (string $type, string $id) use (&$authors, &$tags, &$posts): ?object {
            return match ($type) {
                'authors' => $authors[$id] ?? null,
                'tags' => $tags[$id] ?? null,
                'posts' => $posts[$id] ?? null,
                default => null,
            };
        };

        $identify = static function (object $item): string {
            /** @var mixed $id */
            $id = Accessor::get($item, 'id');

            return \is_scalar($id) ? (string) $id : '';
        };

        // A server-generated create needs a minted id (mirroring the Eloquent auto-increment),
        // so the witness can create a post embedding relationships — the ADR-0009 headline path.
        $assignId = static function (object $item, string $id): void {
            Accessor::set($item, 'id', $id);
        };

        $postProvider = new InMemoryDataProvider('posts', $posts, identify: $identify, assignId: $assignId);
        JsonApi::provider($postProvider);
        JsonApi::persister(new InMemoryDataPersister('posts', $postProvider->store(), static fn(): PostDomain => new PostDomain(), $resolver));

        $authorProvider = new InMemoryDataProvider('authors', $authors, identify: $identify);
        JsonApi::provider($authorProvider);
        JsonApi::persister(new InMemoryDataPersister('authors', $authorProvider->store(), static fn(): AuthorDomain => new AuthorDomain(), $resolver));

        JsonApi::provider(new InMemoryDataProvider('tags', $tags, identify: $identify));
    }
}
