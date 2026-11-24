<?php

declare(strict_types=1);

namespace haddowg\JsonApiLaravel\Tests\Conformance;

use haddowg\JsonApiLaravel\JsonApiServiceProvider;
use haddowg\JsonApiLaravel\Tests\Fixtures\IdKinds\IdKinds;
use haddowg\JsonApiLaravel\Tests\Fixtures\IdKinds\IdKindsServiceProvider;
use Illuminate\Testing\TestResponse;
use Orchestra\Testbench\TestCase as Orchestra;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\Test;

/**
 * Every relation kind, read path, filter and write over one key kind of {@see IdKinds},
 * asserted identically on the in-memory witness and the reference Eloquent provider under
 * each kind, over the rows {@see IdKindsServiceProvider::seed()} lists. Each assertion names
 * rows by reference, so it reads the same whatever the key's wire, PHP and stored forms are.
 */
#[Group('spec:fetching-relationships')]
#[Group('spec:fetching-includes')]
abstract class IdKindsConformanceTestCase extends Orchestra
{
    private const string MEDIA_TYPE = 'application/vnd.api+json';

    private const string COUNTABLE = 'application/vnd.api+json; profile="https://haddowg.github.io/json-api/profiles/countable/"';

    private const string QUERIES = 'application/vnd.api+json; profile="https://haddowg.github.io/json-api/profiles/relationship-queries/ https://haddowg.github.io/json-api/profiles/countable/"';

    /**
     * One of {@see IdKinds::KINDS}.
     */
    abstract protected function kind(): string;

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
        $config->set('id_kinds.kind', $this->kind());
        $config->set('id_kinds.store', $this->store());

        return [JsonApiServiceProvider::class, IdKindsServiceProvider::class];
    }

    #[Test]
    public function fetchingRendersEachRowUnderItsWireId(): void
    {
        $this->read($this->path('ikBooks', 'b1'))->assertJsonPath('data.id', $this->w('b1'))->assertJsonPath('data.attributes.title', 'Dune');
        $this->get($this->path('ikBooks', 'w9'), ['Accept' => self::MEDIA_TYPE])->assertNotFound();
        self::assertSame($this->refs('b1', 'b2', 'b3'), $this->ids('/api/ikBooks'));
        self::assertSame($this->refs('l1', 'l2'), $this->ids('/api/ikLabels'));
    }

    #[Test]
    public function aToOneRendersAndIncludesItsTarget(): void
    {
        $books = $this->read('/api/ikBooks?include=writer,subject,origin&sort=title');
        $books->assertJsonPath('data.0.relationships.writer.data.id', $this->w('w1'));
        $books->assertJsonPath('data.2.relationships.writer.data.id', $this->w('w2'));
        $books->assertJsonPath('data.0.relationships.subject.data', ['type' => 'ikWriters', 'id' => $this->w('w2')]);
        $books->assertJsonPath('data.1.relationships.subject.data', ['type' => 'ikLabels', 'id' => $this->w('l1')]);
        $books->assertJsonPath('data.2.relationships.subject.data', null);
        $books->assertJsonPath('data.0.relationships.origin.data', ['type' => 'ikLabels', 'id' => $this->w('l1')]);
        $books->assertJsonPath('data.1.relationships.origin.data', ['type' => 'ikWriters', 'id' => $this->w('w2')]);
        $books->assertJsonPath('data.2.relationships.origin.data', null);
        self::assertSame($this->keys(['ikLabels', 'l1'], ['ikWriters', 'w1'], ['ikWriters', 'w2']), $this->included($books));

        $writers = $this->read('/api/ikWriters?include=featured&sort=name');
        $writers->assertJsonPath('data.0.relationships.featured.data.id', $this->w('b1'));
        $writers->assertJsonPath('data.1.relationships.featured.data.id', $this->w('b3'));
        $writers->assertJsonPath('data.2.relationships.featured.data', null);
    }

    #[Test]
    public function theToOneEndpointsReadTheirTarget(): void
    {
        $this->read($this->path('ikBooks', 'b1', 'writer'))->assertJsonPath('data.id', $this->w('w1'));
        $this->read($this->path('ikBooks', 'b1', 'relationships/writer'))->assertJsonPath('data.id', $this->w('w1'));
        $this->read($this->path('ikBooks', 'b1', 'subject'))->assertJsonPath('data.attributes.name', 'Bronte');
        $this->read($this->path('ikBooks', 'b2', 'subject'))->assertJsonPath('data.attributes.name', 'classic');
        $this->read($this->path('ikBooks', 'b3', 'subject'))->assertJsonPath('data', null);
        $this->read($this->path('ikBooks', 'b1', 'origin'))->assertJsonPath('data.attributes.name', 'classic');
        $this->read($this->path('ikBooks', 'b2', 'origin'))->assertJsonPath('data.attributes.name', 'Bronte');
        $this->read($this->path('ikBooks', 'b3', 'origin'))->assertJsonPath('data', null);
        $this->read($this->path('ikWriters', 'w1', 'featured'))->assertJsonPath('data.id', $this->w('b1'));
        $this->read($this->path('ikWriters', 'w3', 'relationships/featured'))->assertJsonPath('data', null);
    }

    #[Test]
    public function aToManyRendersAndIncludesItsMembers(): void
    {
        $writers = $this->read('/api/ikWriters?include=books,authored,penned&sort=name');
        foreach (['books', 'authored', 'penned'] as $relation) {
            self::assertSame($this->refs('b1', 'b2'), $this->sortedIds($writers->json("data.0.relationships.{$relation}.data")), $relation);
            self::assertSame($this->refs('b3'), $this->sortedIds($writers->json("data.1.relationships.{$relation}.data")), $relation);
            self::assertSame([], $this->sortedIds($writers->json("data.2.relationships.{$relation}.data")), $relation);
        }
        self::assertSame($this->keys(['ikBooks', 'b1'], ['ikBooks', 'b2'], ['ikBooks', 'b3']), $this->included($writers));

        $books = $this->read('/api/ikBooks?include=labels&sort=title');
        self::assertSame($this->refs('l1', 'l2'), $this->sortedIds($books->json('data.0.relationships.labels.data')));
        self::assertSame($this->refs('l1'), $this->sortedIds($books->json('data.1.relationships.labels.data')));
        self::assertSame([], $this->sortedIds($books->json('data.2.relationships.labels.data')));
        self::assertSame($this->keys(['ikLabels', 'l1'], ['ikLabels', 'l2']), $this->included($books));

        $pins = $this->read('/api/ikBooks?sort=title');
        self::assertSame($this->keys(['ikLabels', 'l2'], ['ikWriters', 'w1']), $this->identifiers($pins->json('data.0.relationships.pins.data')));
        self::assertSame([], $this->identifiers($pins->json('data.1.relationships.pins.data')));

        self::assertSame($this->refs('b1', 'b2'), $this->sortedIds($this->read($this->path('ikLabels', 'l1') . '?include=books')->json('data.relationships.books.data')));
    }

    #[Test]
    public function theToManyEndpointsReadTheirMembers(): void
    {
        foreach (['books', 'authored', 'penned'] as $relation) {
            self::assertSame($this->refs('b1', 'b2'), $this->ids($this->path('ikWriters', 'w1', $relation)), $relation);
            self::assertSame($this->refs('b1', 'b2'), $this->ids($this->path('ikWriters', 'w1', 'relationships/' . $relation)), $relation);
            self::assertSame([], $this->ids($this->path('ikWriters', 'w3', $relation)), $relation);
        }
        self::assertSame($this->refs('l1', 'l2'), $this->ids($this->path('ikBooks', 'b1', 'labels')));
        self::assertSame($this->refs('l1'), $this->ids($this->path('ikBooks', 'b2', 'relationships/labels')));
        self::assertSame($this->refs('b1', 'b2'), $this->ids($this->path('ikLabels', 'l1', 'books')));
    }

    #[Test]
    public function countsCountEachParentsMembers(): void
    {
        $writers = $this->read('/api/ikWriters?withCount=books,authored,penned&sort=name', self::COUNTABLE);
        foreach (['books', 'authored', 'penned'] as $relation) {
            $writers->assertJsonPath("data.0.relationships.{$relation}.meta.total", 2);
            $writers->assertJsonPath("data.1.relationships.{$relation}.meta.total", 1);
            $writers->assertJsonPath("data.2.relationships.{$relation}.meta.total", 0);
            $this->read($this->path('ikWriters', 'w1', $relation) . '?page[size]=1&withCount=_self_', self::COUNTABLE)->assertJsonPath('meta.total', 2);
        }

        $books = $this->read('/api/ikBooks?withCount=labels&sort=title', self::COUNTABLE);
        $books->assertJsonPath('data.0.relationships.labels.meta.total', 2);
        $books->assertJsonPath('data.1.relationships.labels.meta.total', 1);
        $books->assertJsonPath('data.2.relationships.labels.meta.total', 0);
        $this->read($this->path('ikBooks', 'b1', 'writer') . '?withCount=books', self::COUNTABLE)->assertJsonPath('data.relationships.books.meta.total', 2);
    }

    #[Test]
    public function aRelatedQueryWindowsEachParent(): void
    {
        foreach (['books', 'authored', 'penned'] as $relation) {
            $writers = $this->read("/api/ikWriters?sort=name&include={$relation}&withCount={$relation}&relatedQuery[{$relation}][filter][title]=m", self::QUERIES);
            self::assertSame($this->refs('b2'), $this->sortedIds($writers->json("data.0.relationships.{$relation}.data")), $relation);
            $writers->assertJsonPath("data.0.relationships.{$relation}.meta.total", 1);
            self::assertSame([], $this->sortedIds($writers->json("data.1.relationships.{$relation}.data")), $relation);

            $sorted = $this->read("/api/ikWriters?sort=name&include={$relation}&relatedQuery[{$relation}][sort]=-title", self::QUERIES);
            self::assertSame([$this->w('b2'), $this->w('b1')], \array_column((array) $sorted->json("data.0.relationships.{$relation}.data"), 'id'), $relation);
            self::assertSame($this->refs('b3'), $this->sortedIds($sorted->json("data.1.relationships.{$relation}.data")), $relation);
        }

        $books = $this->read('/api/ikBooks?sort=title&include=labels&relatedQuery[labels][filter][name]=sci', self::QUERIES);
        self::assertSame($this->refs('l2'), $this->sortedIds($books->json('data.0.relationships.labels.data')));
    }

    #[Test]
    public function aCursorWalksEveryRowOnce(): void
    {
        self::assertSame([$this->w('b1'), $this->w('b2'), $this->w('b3')], $this->walk('/api/ikBooks?page[size]=1&sort=title'));
        self::assertSame($this->refs('b1', 'b2', 'b3'), $this->sorted($this->walk('/api/ikBooks?page[size]=2')));
        foreach (['books', 'authored', 'penned'] as $relation) {
            self::assertSame([$this->w('b1'), $this->w('b2')], $this->walk($this->path('ikWriters', 'w1', $relation) . '?page[size]=1&sort=title'), $relation);
        }
    }

    #[Test]
    #[Group('spec:fetching-filtering')]
    public function filtersCompareKeysInTheirStoredForm(): void
    {
        self::assertSame($this->refs('b1', 'b3'), $this->ids('/api/ikBooks?filter[id]=' . $this->w('b1') . ',' . $this->w('b3')));
        self::assertSame($this->refs('b1', 'b2'), $this->ids('/api/ikBooks?filter[writer]=' . $this->w('w1')));
        self::assertSame([], $this->ids('/api/ikBooks?filter[writer]=' . $this->w('w3')));
        self::assertSame($this->refs('b1'), $this->ids('/api/ikBooks?filter[labels]=' . $this->w('l2')));
        self::assertSame($this->refs('b1', 'b2'), $this->ids('/api/ikBooks?filter[labels]=' . $this->w('l1')));
        self::assertSame([], $this->ids('/api/ikBooks?filter[labels]=' . $this->w('l3')));
        self::assertSame($this->refs('b1', 'b2'), $this->ids('/api/ikBooks?filter[writerName]=Ada'));
        self::assertSame($this->refs('b1', 'b2'), $this->ids('/api/ikBooks?filter[labelled]=true'));
        self::assertSame($this->refs('b3'), $this->ids('/api/ikBooks?filter[unlabelled]=true'));
        self::assertSame($this->refs('w2'), $this->ids('/api/ikWriters?filter[books]=' . $this->w('b3')));
        self::assertSame($this->refs('w1', 'w3'), $this->ids('/api/ikWriters?filter[id]=' . $this->w('w1') . ',' . $this->w('w3')));
    }

    #[Test]
    #[Group('spec:fetching-filtering')]
    public function aMalformedKeyMatchesNothing(): void
    {
        // A filter whose value its id format refuses is a 400; any other matches no row,
        // where PostgreSQL would refuse to compare it with an integer or uuid column.
        foreach (['id', 'writer', 'labels', 'writerKey', 'writerKeys'] as $filter) {
            $response = $this->get('/api/ikBooks?filter[' . $filter . ']=not%20a%20key', ['Accept' => self::MEDIA_TYPE]);
            self::assertContains($response->getStatusCode(), [200, 400], $filter . ': ' . $response->getContent());
            if ($response->getStatusCode() === 200) {
                self::assertSame([], $response->json('data'), $filter);
            }
        }
        $linkage = $this->write('PATCH', $this->path('ikBooks', 'b3', 'relationships/writer'), ['data' => ['type' => 'ikWriters', 'id' => 'not a key']]);
        self::assertContains($linkage->getStatusCode(), [400, 404, 422], (string) $linkage->getContent());
    }

    #[Test]
    #[Group('spec:fetching-filtering')]
    public function aMorphFilterMatchesTheTargetsTheScopeAdmits(): void
    {
        // Only Eloquent compares a native morphTo, which declares no columns.
        if ($this->store() === 'eloquent') {
            self::assertSame($this->refs('b1'), $this->ids('/api/ikBooks?filter[subject][type]=ikWriters'));
            self::assertSame($this->refs('b2'), $this->ids('/api/ikBooks?filter[subject][type]=ikLabels'));
            self::assertSame($this->refs('b2'), $this->ids('/api/ikBooks?filter[subject][type]=ikLabels&filter[subject][id]=' . $this->w('l1')));
            self::assertSame($this->refs('b1'), $this->ids('/api/ikBooks?filter[subject][type]=ikWriters&filter[subject][id]=' . $this->w('w2')));
            self::assertSame([], $this->ids('/api/ikBooks?filter[subject][type]=ikWriters&filter[subject][id]=' . $this->w('w1')));
        }
        self::assertSame($this->refs('b1'), $this->ids('/api/ikBooks?filter[origin][type]=ikLabels'));
        self::assertSame($this->refs('b2'), $this->ids('/api/ikBooks?filter[origin][type]=ikWriters&filter[origin][id]=' . $this->w('w2')));
        self::assertSame($this->refs('b1'), $this->ids('/api/ikBooks?filter[pinned][type]=ikWriters'));
        self::assertSame($this->refs('b1'), $this->ids('/api/ikBooks?filter[pinned][type]=ikLabels'));
        self::assertSame($this->refs('b1'), $this->ids('/api/ikBooks?filter[pinned][type]=ikLabels&filter[pinned][id]=' . $this->w('l2')));
        self::assertSame([], $this->ids('/api/ikBooks?filter[pinned][type]=ikLabels&filter[pinned][id]=' . $this->w('l3')));
    }

    #[Test]
    #[Group('spec:updating-relationships')]
    public function toOneWritesRepointTheKey(): void
    {
        $this->write('PATCH', $this->path('ikBooks', 'b3', 'relationships/writer'), ['data' => ['type' => 'ikWriters', 'id' => $this->w('w3')]])->assertSuccessful();
        $this->read($this->path('ikBooks', 'b3'))->assertJsonPath('data.relationships.writer.data.id', $this->w('w3'));
        self::assertSame($this->refs('b3'), $this->ids($this->path('ikWriters', 'w3', 'authored')));

        $this->write('PATCH', $this->path('ikBooks', 'b3', 'relationships/subject'), ['data' => ['type' => 'ikWriters', 'id' => $this->w('w3')]])->assertSuccessful();
        $this->read($this->path('ikBooks', 'b3', 'subject'))->assertJsonPath('data.attributes.name', 'Cleo');
        if ($this->store() === 'eloquent') {
            self::assertSame($this->refs('b3'), $this->ids('/api/ikBooks?filter[subject][type]=ikWriters&filter[subject][id]=' . $this->w('w3')));
        }

        $this->write('PATCH', $this->path('ikWriters', 'w3', 'relationships/featured'), ['data' => ['type' => 'ikBooks', 'id' => $this->w('b2')]])->assertSuccessful();
        $this->read($this->path('ikWriters', 'w3', 'featured'))->assertJsonPath('data.id', $this->w('b2'));
    }

    #[Test]
    #[Group('spec:updating-relationships')]
    public function toManyWritesReplaceAddAndRemoveMembers(): void
    {
        $this->write('PATCH', $this->path('ikBooks', 'b3', 'relationships/labels'), ['data' => [['type' => 'ikLabels', 'id' => $this->w('l2')]]])->assertSuccessful();
        $this->write('POST', $this->path('ikBooks', 'b3', 'relationships/labels'), ['data' => [['type' => 'ikLabels', 'id' => $this->w('l1')]]])->assertSuccessful();
        self::assertSame($this->refs('l1', 'l2'), $this->ids($this->path('ikBooks', 'b3', 'relationships/labels')));
        $this->write('DELETE', $this->path('ikBooks', 'b3', 'relationships/labels'), ['data' => [['type' => 'ikLabels', 'id' => $this->w('l2')]]])->assertSuccessful();
        self::assertSame($this->refs('l1'), $this->ids($this->path('ikBooks', 'b3', 'relationships/labels')));

        foreach (['books', 'authored', 'penned'] as $relation) {
            $this->write('PATCH', $this->path('ikWriters', 'w3', 'relationships/' . $relation), ['data' => [['type' => 'ikBooks', 'id' => $this->w('b3')]]])->assertSuccessful();
            self::assertSame($this->refs('b3'), $this->ids($this->path('ikWriters', 'w3', $relation)), $relation);
            // The witness keeps a native to-many and its inverse as separate lists.
            if ($relation !== 'books' || $this->store() === 'eloquent') {
                self::assertSame([], $this->ids($this->path('ikWriters', 'w2', $relation)), $relation);
            }
            $this->write('POST', $this->path('ikWriters', 'w3', 'relationships/' . $relation), ['data' => [['type' => 'ikBooks', 'id' => $this->w('b2')]]])->assertSuccessful();
            self::assertSame($this->refs('b2', 'b3'), $this->ids($this->path('ikWriters', 'w3', 'relationships/' . $relation)), $relation);
            $this->write('DELETE', $this->path('ikWriters', 'w3', 'relationships/' . $relation), ['data' => [['type' => 'ikBooks', 'id' => $this->w('b2')]]])->assertSuccessful();
            self::assertSame($this->refs('b3'), $this->ids($this->path('ikWriters', 'w3', 'relationships/' . $relation)), $relation);
            $this->write('PATCH', $this->path('ikWriters', 'w2', 'relationships/' . $relation), ['data' => [['type' => 'ikBooks', 'id' => $this->w('b3')]]])->assertSuccessful();
        }
    }

    #[Test]
    #[Group('spec:updating-relationships')]
    public function aMissingOrMistypedTargetIsRefused(): void
    {
        $this->write('PATCH', $this->path('ikBooks', 'b3', 'relationships/writer'), ['data' => ['type' => 'ikWriters', 'id' => $this->w('w9')]])->assertNotFound();
        $this->write('POST', $this->path('ikBooks', 'b3', 'relationships/labels'), ['data' => [['type' => 'ikLabels', 'id' => $this->w('l3')]]])->assertNotFound();
        $this->write('PATCH', $this->path('ikBooks', 'b3', 'relationships/writer'), ['data' => ['type' => 'ikLabels', 'id' => $this->w('l1')]])->assertStatus(409);
        $this->read($this->path('ikBooks', 'b3'))->assertJsonPath('data.relationships.writer.data.id', $this->w('w2'));
    }

    #[Test]
    #[Group('spec:crud-creating')]
    public function createTakesAClientIdAndLinkage(): void
    {
        $id = $this->w('b4');
        $this->write('POST', '/api/ikBooks', ['data' => [
            'type' => 'ikBooks',
            'id' => $id,
            'attributes' => ['title' => 'Middlemarch'],
            'relationships' => [
                'writer' => ['data' => ['type' => 'ikWriters', 'id' => $this->w('w2')]],
                'labels' => ['data' => [['type' => 'ikLabels', 'id' => $this->w('l1')]]],
                'subject' => ['data' => ['type' => 'ikLabels', 'id' => $this->w('l2')]],
            ],
        ]])->assertCreated()->assertJsonPath('data.id', $id);

        $book = $this->read($this->path('ikBooks', 'b4') . '?include=writer,labels,subject');
        $book->assertJsonPath('data.relationships.writer.data.id', $this->w('w2'));
        $book->assertJsonPath('data.relationships.subject.data.id', $this->w('l2'));
        self::assertSame($this->refs('l1'), $this->sortedIds($book->json('data.relationships.labels.data')));
        self::assertSame($this->refs('b3', 'b4'), $this->ids($this->path('ikWriters', 'w2', 'authored')));
        self::assertSame($this->refs('b3'), $this->ids($this->path('ikWriters', 'w2', 'penned')));

        if (IdKinds::mintsKeys() && $this->store() === 'eloquent') {
            $created = $this->write('POST', '/api/ikWriters', ['data' => ['type' => 'ikWriters', 'attributes' => ['name' => 'Dora']]])->assertCreated();
            $minted = $created->json('data.id');
            self::assertIsString($minted);
            $this->read('/api/ikWriters/' . $minted)->assertJsonPath('data.attributes.name', 'Dora');
        }
    }

    #[Test]
    #[Group('ext:actions')]
    public function anActionReadsItsResourceAndInputLinkageByKey(): void
    {
        $body = ['data' => ['type' => 'ikBooks', 'id' => $this->w('b1'), 'relationships' => ['writer' => ['data' => ['type' => 'ikWriters', 'id' => $this->w('w2')]]]]];
        $this->write('PATCH', $this->path('ikBooks', 'b1', '-actions/repoint'), $body)->assertOk()
            ->assertJsonPath('data.id', $this->w('b1'))
            ->assertJsonPath('data.relationships.writer.data.id', $this->w('w2'));

        $missing = ['data' => ['type' => 'ikBooks', 'id' => $this->w('b1'), 'relationships' => ['writer' => ['data' => ['type' => 'ikWriters', 'id' => $this->w('w9')]]]]];
        $this->write('PATCH', $this->path('ikBooks', 'b1', '-actions/repoint'), $missing)->assertNotFound();
        $this->write('PATCH', $this->path('ikBooks', 'w9', '-actions/repoint'), $body)->assertNotFound();
    }

    private function w(string $ref): string
    {
        return IdKinds::wire($ref);
    }

    /**
     * @return list<string>
     */
    private function refs(string ...$refs): array
    {
        return $this->sorted(\array_values(\array_map(IdKinds::wire(...), $refs)));
    }

    /**
     * @param array{string, string} ...$pairs
     *
     * @return list<string>
     */
    private function keys(array ...$pairs): array
    {
        return $this->sorted(\array_values(\array_map(static fn(array $pair): string => $pair[0] . ':' . IdKinds::wire($pair[1]), $pairs)));
    }

    private function path(string $type, string $ref, ?string $tail = null): string
    {
        return '/api/' . $type . '/' . IdKinds::wire($ref) . ($tail !== null ? '/' . $tail : '');
    }

    /**
     * The sorted wire ids of the `data` a read of `$path` returns.
     *
     * @return list<string>
     */
    private function ids(string $path): array
    {
        return $this->sortedIds($this->read($path)->json('data'));
    }

    /**
     * @return list<string>
     */
    private function sortedIds(mixed $data): array
    {
        return $this->sorted(\array_map(static fn(string $key): string => \explode(':', $key, 2)[1], $this->identifiers($data)));
    }

    /**
     * Each resource or identifier in `$data` as `type:id`, sorted.
     *
     * @return list<string>
     */
    private function identifiers(mixed $data): array
    {
        if ($data === null) {
            return [];
        }
        self::assertIsArray($data);
        $keys = [];
        foreach ($data as $resource) {
            self::assertIsArray($resource);
            self::assertIsString($resource['type'] ?? null);
            self::assertIsString($resource['id'] ?? null);
            $keys[] = $resource['type'] . ':' . $resource['id'];
        }

        return $this->sorted($keys);
    }

    /**
     * @param TestResponse<\Symfony\Component\HttpFoundation\Response> $response
     *
     * @return list<string>
     */
    private function included(TestResponse $response): array
    {
        return $this->identifiers($response->json('included') ?? []);
    }

    /**
     * Every id a cursor walk from `$path` reads, following each `next` link, in order.
     *
     * @return list<string>
     */
    private function walk(string $path): array
    {
        $ids = [];
        $next = $path . (\str_contains($path, '?') ? '&' : '?') . 'page[after]=';
        $next = $path;
        for ($pages = 0; $pages < 10 && $next !== null; ++$pages) {
            $response = $this->read($next);
            foreach ((array) $response->json('data') as $resource) {
                self::assertIsArray($resource);
                self::assertIsString($resource['id'] ?? null);
                $ids[] = $resource['id'];
            }
            $link = $response->json('links.next');
            $href = \is_array($link) ? ($link['href'] ?? null) : $link;
            $next = \is_string($href) ? (string) \preg_replace('#^https?://[^/]+#', '', $href) : null;
        }

        return $ids;
    }

    /**
     * @param list<string> $ids
     *
     * @return list<string>
     */
    private function sorted(array $ids): array
    {
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
