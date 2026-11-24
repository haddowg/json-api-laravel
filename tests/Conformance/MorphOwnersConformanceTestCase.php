<?php

declare(strict_types=1);

namespace haddowg\JsonApiLaravel\Tests\Conformance;

use haddowg\JsonApiLaravel\JsonApiServiceProvider;
use haddowg\JsonApiLaravel\Tests\Fixtures\MorphOwners\MorphOwnersServiceProvider;
use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Support\Facades\Gate;
use Illuminate\Testing\TestResponse;
use Orchestra\Testbench\TestCase as Orchestra;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\Test;

/**
 * A `HasMany` and a `HasOne` whose foreign key names a `MorphTo` identified by columns, over
 * posts and videos whose integer ids collide, asserted identically on the in-memory witness and
 * the reference Eloquent provider over the rows {@see MorphOwnersServiceProvider::seed()}
 * lists. Every read and write scopes on the type column as well as the id, so a video's note
 * never reads as the post's with the same id.
 */
#[Group('spec:fetching-relationships')]
abstract class MorphOwnersConformanceTestCase extends Orchestra
{
    private const string MEDIA_TYPE = 'application/vnd.api+json';

    private const string QUERIES = 'application/vnd.api+json; profile="https://haddowg.github.io/json-api/profiles/relationship-queries/ https://haddowg.github.io/json-api/profiles/countable/"';

    /**
     * `eloquent` or `memory`.
     */
    abstract protected function store(): string;

    /**
     * @param \Illuminate\Foundation\Application $app
     *
     * @return array<int, class-string>
     */
    protected function getPackageProviders($app): array
    {
        /** @var \Illuminate\Contracts\Config\Repository $config */
        $config = $app['config'];
        $config->set('morph_owners.store', $this->store());

        return [JsonApiServiceProvider::class, MorphOwnersServiceProvider::class];
    }

    #[Test]
    public function eachOwnerReadsOnlyTheRowsOfItsType(): void
    {
        self::assertSame(['1', '2'], $this->ids('/api/moPosts/1/notes'));
        self::assertSame(['1', '2'], $this->ids('/api/moPosts/1/relationships/notes'));
        self::assertSame([], $this->ids('/api/moPosts/2/notes'));
        $this->read('/api/moVideos/1/note')->assertJsonPath('data.id', '3');
        $this->read('/api/moVideos/1/relationships/note')->assertJsonPath('data.id', '3');
        $this->read('/api/moVideos/2/note')->assertJsonPath('data.id', '4');
        $this->read('/api/moNotes/3/owner')->assertJsonPath('data.type', 'moVideos')->assertJsonPath('data.id', '1');
    }

    #[Test]
    #[Group('spec:fetching-includes')]
    public function includesAndCountsReadOnlyTheRowsOfEachOwnersType(): void
    {
        $posts = $this->read('/api/moPosts?include=notes&withCount=notes', self::QUERIES);
        self::assertSame(['1', '2'], $this->sortedIds($posts->json('data.0.relationships.notes.data')));
        self::assertSame([], $this->sortedIds($posts->json('data.1.relationships.notes.data')));
        $posts->assertJsonPath('data.0.relationships.notes.meta.total', 2);
        $posts->assertJsonPath('data.1.relationships.notes.meta.total', 0);
        self::assertSame(['1', '2'], $this->sortedIds($posts->json('included')));

        $videos = $this->read('/api/moVideos?include=note');
        $videos->assertJsonPath('data.0.relationships.note.data.id', '3');
        $videos->assertJsonPath('data.1.relationships.note.data.id', '4');

        $windowed = $this->read('/api/moPosts?include=notes&withCount=notes&relatedQuery[notes][filter][body]=n2', self::QUERIES);
        self::assertSame(['2'], $this->sortedIds($windowed->json('data.0.relationships.notes.data')));
        $windowed->assertJsonPath('data.0.relationships.notes.meta.total', 1);
        $this->read('/api/moPosts/1/notes?page[size]=1&withCount=_self_', self::QUERIES)->assertJsonPath('meta.total', 2);
    }

    #[Test]
    public function aForeignKeyNamingANativeMorphToReadsOnlyTheRowsOfItsType(): void
    {
        self::assertSame(['2'], $this->ids('/api/moPosts/1/authoredNotes'));
        self::assertSame(['3'], $this->ids('/api/moPosts/2/relationships/authoredNotes'));
        $posts = $this->read('/api/moPosts?include=authoredNotes&withCount=authoredNotes', self::QUERIES);
        self::assertSame(['2'], $this->sortedIds($posts->json('data.0.relationships.authoredNotes.data')));
        $posts->assertJsonPath('data.0.relationships.authoredNotes.meta.total', 1);

        $this->write('PATCH', '/api/moPosts/1/relationships/authoredNotes', ['data' => [['type' => 'moNotes', 'id' => '1'], ['type' => 'moNotes', 'id' => '2']]])->assertSuccessful();
        self::assertSame(['1', '2'], $this->ids('/api/moPosts/1/authoredNotes'));
        $this->read('/api/moNotes/1/author')->assertJsonPath('data.type', 'moPosts')->assertJsonPath('data.id', '1');
        $this->write('DELETE', '/api/moPosts/1/relationships/authoredNotes', ['data' => [['type' => 'moNotes', 'id' => '2']]])->assertSuccessful();
        $this->read('/api/moNotes/2/relationships/author')->assertJsonPath('data', null);
    }

    #[Test]
    #[Group('spec:updating-relationships')]
    public function movingARowAuthorizesTheOwnerItLeavesAsItsOwnType(): void
    {
        // Note 1 is written by video 1, which shares its id with post 1. Only post updates are
        // unchecked, so authorizing the video as a post would let the move through.
        Gate::define('update', static fn(?Authenticatable $user, mixed $subject = null): bool => false);

        $this->write('POST', '/api/moPosts/2/relationships/authoredNotes', ['data' => [['type' => 'moNotes', 'id' => '1']]])->assertStatus(403);
        $this->read('/api/moNotes/1/author')->assertJsonPath('data.type', 'moVideos')->assertJsonPath('data.id', '1');

        Gate::define('update', static fn(?Authenticatable $user, mixed $subject = null): bool => \is_object($subject) && \str_ends_with($subject::class, 'Video') || \is_object($subject) && \str_ends_with($subject::class, 'VideoRecord'));
        $this->write('POST', '/api/moPosts/2/relationships/authoredNotes', ['data' => [['type' => 'moNotes', 'id' => '1']]])->assertSuccessful();
        self::assertSame(['1', '3'], $this->ids('/api/moPosts/2/authoredNotes'));
    }

    #[Test]
    public function aNativeMorphManyReadsOnlyTheRowsOfItsType(): void
    {
        self::assertSame(['1'], $this->ids('/api/moVideos/1/authoredNotes'));
        self::assertSame(['4'], $this->ids('/api/moVideos/2/relationships/authoredNotes'));
        $videos = $this->read('/api/moVideos?include=authoredNotes&withCount=authoredNotes', self::QUERIES);
        self::assertSame(['1'], $this->sortedIds($videos->json('data.0.relationships.authoredNotes.data')));
        $videos->assertJsonPath('data.0.relationships.authoredNotes.meta.total', 1);
        $videos->assertJsonPath('data.1.relationships.authoredNotes.meta.total', 1);
        if ($this->store() === 'eloquent') {
            self::assertSame(['1', '2'], $this->ids('/api/moVideos?filter[authored]=true'));
        }
    }

    #[Test]
    #[Group('spec:fetching-sorting')]
    public function aSortThroughAMorphOneReadsTheRowOfItsType(): void
    {
        // jsonapi:optimize refuses it on Eloquent, which has no join to sort by.
        if ($this->store() === 'eloquent') {
            self::markTestSkipped('An Eloquent sort cannot reach through a foreign key.');
        }

        self::assertSame(['1', '2'], \array_column((array) $this->read('/api/moVideos?sort=noteBody')->json('data'), 'id'));
        self::assertSame(['2', '1'], \array_column((array) $this->read('/api/moVideos?sort=-noteBody')->json('data'), 'id'));
    }

    #[Test]
    #[Group('spec:fetching-filtering')]
    public function filtersThroughTheRelationMatchOnlyItsType(): void
    {
        self::assertSame(['1'], $this->ids('/api/moPosts?filter[annotated]=true'));
        self::assertSame(['2'], $this->ids('/api/moPosts?filter[unannotated]=true'));
        self::assertSame(['1'], $this->ids('/api/moPosts?filter[notes]=1'));
        self::assertSame([], $this->ids('/api/moPosts?filter[notes]=3'));
        self::assertSame([], $this->ids('/api/moPosts?filter[notes]=5'));
        self::assertSame(['1'], $this->ids('/api/moPosts?filter[noteBody]=n2'));
        self::assertSame([], $this->ids('/api/moPosts?filter[noteBody]=n3'));
        self::assertSame(['1', '2'], $this->ids('/api/moVideos?filter[noted]=true'));
        self::assertSame(['1'], $this->ids('/api/moVideos?filter[note]=3'));
        self::assertSame([], $this->ids('/api/moVideos?filter[note]=1'));
        self::assertSame(['1', '2'], $this->ids('/api/moPosts?filter[authored]=true'));
    }

    #[Test]
    #[Group('spec:updating-relationships')]
    public function writesSetAndClearBothColumns(): void
    {
        $this->write('PATCH', '/api/moPosts/2/relationships/notes', ['data' => [['type' => 'moNotes', 'id' => '3']]])->assertSuccessful();
        self::assertSame(['3'], $this->ids('/api/moPosts/2/notes'));
        $this->read('/api/moVideos/1/note')->assertJsonPath('data', null);
        $this->read('/api/moNotes/3/owner')->assertJsonPath('data.type', 'moPosts')->assertJsonPath('data.id', '2');

        $this->write('DELETE', '/api/moPosts/1/relationships/notes', ['data' => [['type' => 'moNotes', 'id' => '2']]])->assertSuccessful();
        $this->read('/api/moNotes/2/relationships/owner')->assertJsonPath('data', null);
        self::assertSame(['1'], $this->ids('/api/moPosts/1/notes'));

        $this->write('PATCH', '/api/moVideos/2/relationships/note', ['data' => ['type' => 'moNotes', 'id' => '1']])->assertSuccessful();
        $this->read('/api/moVideos/2/note')->assertJsonPath('data.id', '1');
        $this->read('/api/moNotes/4/relationships/owner')->assertJsonPath('data', null);
        self::assertSame([], $this->ids('/api/moPosts/1/notes'));
        self::assertSame([], $this->ids('/api/moVideos/1/relationships/note'));
    }

    /**
     * @return list<string>
     */
    private function ids(string $path): array
    {
        $data = $this->read($path)->json('data');
        if ($data === null) {
            return [];
        }
        self::assertIsArray($data);

        return \is_string($data['id'] ?? null) ? [$data['id']] : $this->sortedIds($data);
    }

    /**
     * @return list<string>
     */
    private function sortedIds(mixed $data): array
    {
        self::assertIsArray($data);
        $ids = [];
        foreach ($data as $resource) {
            self::assertIsArray($resource);
            self::assertIsString($resource['id'] ?? null);
            $ids[] = $resource['id'];
        }
        \sort($ids);

        return $ids;
    }

    /**
     * @return TestResponse<\Symfony\Component\HttpFoundation\Response>
     */
    private function read(string $uri, string $accept = self::MEDIA_TYPE): TestResponse
    {
        $response = $this->get($uri, ['Accept' => $accept]);
        $response->assertOk();

        return $response;
    }

    /**
     * @param array<string, mixed> $body
     *
     * @return TestResponse<\Symfony\Component\HttpFoundation\Response>
     */
    private function write(string $method, string $uri, array $body): TestResponse
    {
        return $this->json($method, $uri, $body, ['Accept' => self::MEDIA_TYPE, 'Content-Type' => self::MEDIA_TYPE]);
    }
}
