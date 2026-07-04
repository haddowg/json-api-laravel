<?php

declare(strict_types=1);

namespace haddowg\JsonApiLaravel\Tests\Fixtures\Relations;

use haddowg\JsonApiLaravel\DataProvider\InMemoryDataProvider;
use haddowg\JsonApiLaravel\Facades\JsonApi;
use Illuminate\Support\ServiceProvider;

/**
 * Wires the isolated blog fixture set: discovery points at this directory (finding the
 * three read-only resources) and one seeded {@see InMemoryDataProvider} is registered per
 * type over a linked author/tag/post object graph. Kept out of the shared music-catalog
 * workbench so the polymorphic + belongsToMany relation reads exercise the in-memory
 * witness without an Eloquent morph map / pivot table.
 */
final class BlogRelationsServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        JsonApi::discover([__DIR__]);

        [$authors, $tags, $posts] = $this->graph();

        JsonApi::provider(new InMemoryDataProvider('authors', $authors));
        JsonApi::provider(new InMemoryDataProvider('tags', $tags));
        JsonApi::provider(new InMemoryDataProvider('posts', $posts));
    }

    /**
     * The linked object graph: two authors, two tags, and three posts wiring every
     * relation cardinality — a post whose polymorphic `feature` is a tag, one whose
     * feature is an author, and an empty post (null to-one, empty to-many); a mixed
     * polymorphic `related` list; and a `belongsToMany` `tags` list.
     *
     * @return array{0: array<int|string, Author>, 1: array<int|string, Tag>, 2: array<int|string, Post>}
     */
    private function graph(): array
    {
        $ada = new Author('1', 'Ada');
        $grace = new Author('2', 'Grace');
        $authors = ['1' => $ada, '2' => $grace];

        $php = new Tag('1', 'php');
        $json = new Tag('2', 'json');
        $tags = ['1' => $php, '2' => $json];

        $posts = [
            '1' => new Post('1', 'Hello', author: $ada, tags: [$php, $json], feature: $json, related: [$ada, $php]),
            '2' => new Post('2', 'World', author: $grace, tags: [$php], feature: $ada, related: [$json]),
            '3' => new Post('3', 'Empty', author: null, tags: [], feature: null, related: []),
        ];

        return [$authors, $tags, $posts];
    }
}
