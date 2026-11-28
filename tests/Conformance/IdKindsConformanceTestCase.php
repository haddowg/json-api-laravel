<?php

declare(strict_types=1);

namespace haddowg\JsonApiLaravel\Tests\Conformance;

use haddowg\JsonApi\Resource\Field\StoredIdForms;
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
     * How a request names a row: `wire`, by its wire id, or `upper`, by its id in capitals.
     */
    protected function sentCase(): string
    {
        return 'wire';
    }

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
        $config->set('id_kinds.sent', $this->sentCase());
        $config->set('jsonapi.atomic_operations.enabled', true);

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
        $books->assertJsonPath('data.2.relationships.origin.data', ['type' => 'ikWriters', 'id' => $this->w('w2')]);
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
        $this->read($this->path('ikBooks', 'b3', 'origin'))->assertJsonPath('data.attributes.name', 'Bronte');
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
    public function aMorphManyReadsTheRowsThatNameItsParentInAnyForm(): void
    {
        self::assertSame($this->refs('b2', 'b3'), $this->ids($this->path('ikWriters', 'w2', 'originated')));
        self::assertSame([], $this->ids($this->path('ikWriters', 'w1', 'relationships/originated')));
        $writers = $this->read('/api/ikWriters?sort=name&include=originated&withCount=originated', self::QUERIES);
        self::assertSame($this->refs('b2', 'b3'), $this->sortedIds($writers->json('data.1.relationships.originated.data')));
        $writers->assertJsonPath('data.1.relationships.originated.meta.total', 2);
        $writers->assertJsonPath('data.0.relationships.originated.meta.total', 0);
        $windowed = $this->read('/api/ikWriters?sort=name&include=originated&relatedQuery[originated][sort]=-title', self::QUERIES);
        self::assertSame([$this->w('b3'), $this->w('b2')], \array_column((array) $windowed->json('data.1.relationships.originated.data'), 'id'));
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
        self::assertSame($this->refs('b1', 'b2'), $this->ids('/api/ikBooks?filter[writerName]=Ada'));
        self::assertSame($this->refs('b1', 'b2'), $this->ids('/api/ikBooks?filter[labelled]=true'));
        self::assertSame($this->refs('b3'), $this->ids('/api/ikBooks?filter[unlabelled]=true'));
    }

    #[Test]
    #[Group('spec:fetching-filtering')]
    public function idFiltersMatchTheRowsTheyName(): void
    {
        self::assertSame($this->refs('b1', 'b3'), $this->ids('/api/ikBooks?filter[id]=' . $this->s('b1') . ',' . $this->s('b3')));
        self::assertSame($this->refs('w1', 'w3'), $this->ids('/api/ikWriters?filter[id]=' . $this->s('w1') . ',' . $this->s('w3')));
        self::assertSame($this->refs('b1', 'b2'), $this->ids('/api/ikBooks?filter[writer]=' . $this->s('w1')));
        self::assertSame([], $this->ids('/api/ikBooks?filter[writer]=' . $this->s('w3')));
        self::assertSame($this->refs('b1'), $this->ids('/api/ikBooks?filter[labels]=' . $this->s('l2')));
        self::assertSame($this->refs('b1', 'b2'), $this->ids('/api/ikBooks?filter[labels]=' . $this->s('l1')));
        self::assertSame([], $this->ids('/api/ikBooks?filter[labels]=' . $this->s('l3')));
        self::assertSame($this->refs('w2'), $this->ids('/api/ikWriters?filter[books]=' . $this->s('b3')));
    }

    #[Test]
    #[Group('spec:fetching-filtering')]
    public function aColumnFilterMatchesAUuidValueInEitherCase(): void
    {
        // The key as the store holds it in the writer's foreign key: the witness keeps wire ids.
        $held = fn(string $ref): int|string => $this->store() === 'memory' ? IdKinds::held($ref) : IdKinds::stored($ref);
        $stored = $held('w1');
        if (IdKinds::kind() === 'binary') {
            self::markTestSkipped('A key stored as bytes has no letter case.');
        }
        if (!\is_string($stored) || !StoredIdForms::isUuidLike($stored)) {
            // Any other key compares exactly.
            self::assertSame($this->refs('b1', 'b2'), $this->ids('/api/ikBooks?filter[writerKey]=' . $stored));
            if (\is_string($stored) && \strtoupper($stored) !== $stored) {
                self::assertSame([], $this->ids('/api/ikBooks?filter[writerKey]=' . \strtoupper($stored)));
                self::assertSame([], $this->ids('/api/ikBooks?filter[writerKeys]=' . \strtoupper($stored)));
            }

            return;
        }

        // The writer's key in the other case than its column keeps it in.
        $flip = static fn(string $key): string => \strtolower($key) === $key ? \strtoupper($key) : \strtolower($key);
        $sent = $flip($stored);
        $second = $held('w2');
        self::assertIsString($second);

        self::assertSame($this->refs('b1', 'b2'), $this->ids('/api/ikBooks?filter[writerKey]=' . $sent));
        self::assertSame($this->refs('b1', 'b2'), $this->ids('/api/ikBooks?filter[writerKeys]=' . $sent));
        self::assertSame($this->refs('b1', 'b2', 'b3'), $this->ids('/api/ikBooks?filter[writerKeys]=' . $sent . ',' . $flip($second)));
        self::assertSame($this->refs('b3'), $this->ids('/api/ikBooks?filter[writerKeyNot]=' . $sent));
        self::assertSame($this->refs('b3'), $this->ids('/api/ikBooks?filter[writerKeysNot]=' . $sent));
        self::assertSame($this->refs('b1', 'b2'), $this->ids($this->path('ikWriters', 'w1', 'books') . '?filter[writerKey]=' . $sent));
        self::assertSame([], $this->ids($this->path('ikWriters', 'w1', 'books') . '?filter[writerKeyNot]=' . $sent));

        // An ordered operator compares exactly: capitals sort before lower case.
        $from = \array_keys(\array_filter(['b1' => $stored, 'b2' => $stored, 'b3' => $second], static fn(string $key): bool => \strcmp($key, $sent) >= 0));
        self::assertSame($this->refs(...$from), $this->ids('/api/ikBooks?filter[writerKeyFrom]=' . $sent));
    }

    #[Test]
    #[Group('spec:fetching-filtering')]
    public function aTraversalEndingOnARelationMatchesAUuidIdInEitherCase(): void
    {
        if (!StoredIdForms::isUuidLike($this->w('w1'))) {
            self::markTestSkipped('Only a uuid id has a letter case to ignore.');
        }

        foreach ([\strtolower(...), \strtoupper(...)] as $case) {
            self::assertSame($this->refs('b1', 'b2'), $this->ids('/api/ikBooks?filter[writerIs]=' . $case($this->w('w1'))));
            self::assertSame($this->refs('b1', 'b2'), $this->ids('/api/ikBooks?filter[writerIn]=' . $case($this->w('w1'))));
            self::assertSame($this->refs('b3'), $this->ids('/api/ikBooks?filter[writerIsNot]=' . $case($this->w('w1'))));
            self::assertSame($this->refs('w2'), $this->ids('/api/ikWriters?filter[bookIs]=' . $case($this->w('b3'))));
            self::assertSame($this->refs('w2'), $this->ids('/api/ikWriters?filter[bookIn]=' . $case($this->w('b3'))));
        }
    }

    #[Test]
    #[Group('spec:fetching-filtering')]
    public function aPivotRowFilterMatchesAUuidKeyInEitherCase(): void
    {
        if ($this->store() === 'memory') {
            self::markTestSkipped('The in-memory witness holds no pivot rows to filter.');
        }
        $stored = IdKinds::stored('w2');
        if (!\is_string($stored) || !StoredIdForms::isUuidLike($stored)) {
            self::markTestSkipped('Only a uuid key has a letter case to ignore.');
        }

        $peers = fn(string $query): array => $this->ids($this->path('ikWriters', 'w1', 'peers') . '?' . $query);
        $other = \strtolower($stored) === $stored ? \strtoupper($stored) : \strtolower($stored);
        foreach ([$other, $stored] as $sent) {
            self::assertSame($this->refs('w2'), $peers('filter[memberKey]=' . $sent), $sent);
            self::assertSame($this->refs('w2'), $peers('filter[memberKeys]=' . $sent), $sent);
            self::assertSame([], $peers('filter[memberKeyNot]=' . $sent), $sent);
            self::assertSame([], $peers('filter[memberKeysNot]=' . $sent), $sent);
        }
        $third = IdKinds::stored('w3');
        self::assertIsString($third);
        self::assertSame($this->refs('w2'), $peers('filter[memberKeyNot]=' . \strtoupper($third)));
        self::assertSame($this->refs('w2'), $peers('filter[memberKeysNot]=' . \strtolower($third)));
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
            self::assertSame($this->refs('b2'), $this->ids('/api/ikBooks?filter[subject][type]=ikLabels&filter[subject][id]=' . $this->s('l1')));
            self::assertSame($this->refs('b1'), $this->ids('/api/ikBooks?filter[subject][type]=ikWriters&filter[subject][id]=' . $this->s('w2')));
            self::assertSame([], $this->ids('/api/ikBooks?filter[subject][type]=ikWriters&filter[subject][id]=' . $this->s('w1')));
        }
        self::assertSame($this->refs('b1'), $this->ids('/api/ikBooks?filter[origin][type]=ikLabels'));
        self::assertSame($this->refs('b2', 'b3'), $this->ids('/api/ikBooks?filter[origin][type]=ikWriters&filter[origin][id]=' . $this->s('w2')));
        self::assertSame($this->refs('b1'), $this->ids('/api/ikBooks?filter[pinned][type]=ikWriters'));
        self::assertSame($this->refs('b1'), $this->ids('/api/ikBooks?filter[pinned][type]=ikLabels'));
        self::assertSame($this->refs('b1'), $this->ids('/api/ikBooks?filter[pinned][type]=ikLabels&filter[pinned][id]=' . $this->s('l2')));
        self::assertSame([], $this->ids('/api/ikBooks?filter[pinned][type]=ikLabels&filter[pinned][id]=' . $this->s('l3')));
    }

    #[Test]
    #[Group('spec:updating-relationships')]
    public function toOneWritesRepointTheKey(): void
    {
        $this->write('PATCH', $this->path('ikBooks', 'b3', 'relationships/writer'), ['data' => ['type' => 'ikWriters', 'id' => $this->s('w3')]])->assertSuccessful();
        $this->read($this->path('ikBooks', 'b3'))->assertJsonPath('data.relationships.writer.data.id', $this->w('w3'));
        self::assertSame($this->refs('b3'), $this->ids($this->path('ikWriters', 'w3', 'authored')));

        $this->write('PATCH', $this->path('ikBooks', 'b3', 'relationships/subject'), ['data' => ['type' => 'ikWriters', 'id' => $this->s('w3')]])->assertSuccessful();
        $this->read($this->path('ikBooks', 'b3', 'subject'))->assertJsonPath('data.attributes.name', 'Cleo');
        if ($this->store() === 'eloquent') {
            self::assertSame($this->refs('b3'), $this->ids('/api/ikBooks?filter[subject][type]=ikWriters&filter[subject][id]=' . $this->s('w3')));
        }

        $this->write('PATCH', $this->path('ikWriters', 'w3', 'relationships/featured'), ['data' => ['type' => 'ikBooks', 'id' => $this->s('b2')]])->assertSuccessful();
        $this->read($this->path('ikWriters', 'w3', 'featured'))->assertJsonPath('data.id', $this->w('b2'));
    }

    #[Test]
    #[Group('spec:updating-relationships')]
    public function toManyWritesReplaceAddAndRemoveMembers(): void
    {
        $this->write('PATCH', $this->path('ikBooks', 'b3', 'relationships/labels'), ['data' => [['type' => 'ikLabels', 'id' => $this->s('l2')]]])->assertSuccessful();
        $this->write('POST', $this->path('ikBooks', 'b3', 'relationships/labels'), ['data' => [['type' => 'ikLabels', 'id' => $this->s('l1')]]])->assertSuccessful();
        self::assertSame($this->refs('l1', 'l2'), $this->ids($this->path('ikBooks', 'b3', 'relationships/labels')));
        $this->write('DELETE', $this->path('ikBooks', 'b3', 'relationships/labels'), ['data' => [['type' => 'ikLabels', 'id' => $this->s('l2')]]])->assertSuccessful();
        self::assertSame($this->refs('l1'), $this->ids($this->path('ikBooks', 'b3', 'relationships/labels')));

        foreach (['books', 'authored', 'penned'] as $relation) {
            $this->write('PATCH', $this->path('ikWriters', 'w3', 'relationships/' . $relation), ['data' => [['type' => 'ikBooks', 'id' => $this->s('b3')]]])->assertSuccessful();
            self::assertSame($this->refs('b3'), $this->ids($this->path('ikWriters', 'w3', $relation)), $relation);
            // The witness keeps a native to-many and its inverse as separate lists.
            if ($relation !== 'books' || $this->store() === 'eloquent') {
                self::assertSame([], $this->ids($this->path('ikWriters', 'w2', $relation)), $relation);
            }
            $this->write('POST', $this->path('ikWriters', 'w3', 'relationships/' . $relation), ['data' => [['type' => 'ikBooks', 'id' => $this->s('b2')]]])->assertSuccessful();
            self::assertSame($this->refs('b2', 'b3'), $this->ids($this->path('ikWriters', 'w3', 'relationships/' . $relation)), $relation);
            $this->write('DELETE', $this->path('ikWriters', 'w3', 'relationships/' . $relation), ['data' => [['type' => 'ikBooks', 'id' => $this->s('b2')]]])->assertSuccessful();
            self::assertSame($this->refs('b3'), $this->ids($this->path('ikWriters', 'w3', 'relationships/' . $relation)), $relation);
            $this->write('PATCH', $this->path('ikWriters', 'w2', 'relationships/' . $relation), ['data' => [['type' => 'ikBooks', 'id' => $this->s('b3')]]])->assertSuccessful();
        }
    }

    #[Test]
    #[Group('spec:updating-relationships')]
    public function aMissingOrMistypedTargetIsRefused(): void
    {
        $this->write('PATCH', $this->path('ikBooks', 'b3', 'relationships/writer'), ['data' => ['type' => 'ikWriters', 'id' => $this->s('w9')]])->assertNotFound();
        $this->write('POST', $this->path('ikBooks', 'b3', 'relationships/labels'), ['data' => [['type' => 'ikLabels', 'id' => $this->s('l3')]]])->assertNotFound();
        $this->write('PATCH', $this->path('ikBooks', 'b3', 'relationships/writer'), ['data' => ['type' => 'ikLabels', 'id' => $this->s('l1')]])->assertStatus(409);
        $this->read($this->path('ikBooks', 'b3'))->assertJsonPath('data.relationships.writer.data.id', $this->w('w2'));
    }

    #[Test]
    #[Group('spec:crud-creating')]
    public function createTakesAClientIdAndLinkage(): void
    {
        $this->write('POST', '/api/ikBooks', ['data' => [
            'type' => 'ikBooks',
            'id' => $this->s('b4'),
            'attributes' => ['title' => 'Middlemarch'],
            'relationships' => [
                'writer' => ['data' => ['type' => 'ikWriters', 'id' => $this->s('w2')]],
                'labels' => ['data' => [['type' => 'ikLabels', 'id' => $this->s('l1')]]],
                'subject' => ['data' => ['type' => 'ikLabels', 'id' => $this->s('l2')]],
            ],
        ]])->assertCreated()->assertJsonPath('data.id', $this->w('b4'));

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
    #[Group('spec:crud-updating')]
    #[Group('spec:crud-deleting')]
    public function updateAndDeleteFindTheRowByItsId(): void
    {
        $this->write('PATCH', $this->path('ikBooks', 'b3'), ['data' => ['type' => 'ikBooks', 'id' => $this->s('b3'), 'attributes' => ['title' => 'Ulysses II']]])
            ->assertOk()
            ->assertJsonPath('data.id', $this->w('b3'))
            ->assertJsonPath('data.relationships.writer.data.id', $this->w('w2'));
        $this->read($this->path('ikBooks', 'b3'))->assertJsonPath('data.attributes.title', 'Ulysses II');

        $this->call('DELETE', $this->path('ikBooks', 'b3'), server: ['HTTP_ACCEPT' => self::MEDIA_TYPE])->assertNoContent();
        $this->get($this->path('ikBooks', 'b3'), ['Accept' => self::MEDIA_TYPE])->assertNotFound();
        self::assertSame($this->refs('b1', 'b2'), $this->ids('/api/ikBooks'));
    }

    #[Test]
    #[Group('ext:atomic')]
    public function atomicOperationsAddressRowsByIdAndLid(): void
    {
        // A lid names a minted key, which only the Eloquent kinds that mint one have.
        $minted = IdKinds::mintsKeys() && $this->store() === 'eloquent';
        $operations = [
            ['op' => 'update', 'ref' => ['type' => 'ikBooks', 'id' => $this->s('b3')], 'data' => ['type' => 'ikBooks', 'id' => $this->s('b3'), 'attributes' => ['title' => 'Ulysses II']]],
            ['op' => 'update', 'ref' => ['type' => 'ikBooks', 'id' => $this->s('b3'), 'relationship' => 'writer'], 'data' => ['type' => 'ikWriters', 'id' => $this->s('w3')]],
            ['op' => 'add', 'ref' => ['type' => 'ikBooks', 'id' => $this->s('b3'), 'relationship' => 'labels'], 'data' => [['type' => 'ikLabels', 'id' => $this->s('l1')]]],
            ['op' => 'remove', 'ref' => ['type' => 'ikBooks', 'id' => $this->s('b2')]],
        ];
        if ($minted) {
            $operations[] = ['op' => 'add', 'data' => ['type' => 'ikWriters', 'lid' => 'dora', 'attributes' => ['name' => 'Dora']]];
            $operations[] = ['op' => 'update', 'ref' => ['type' => 'ikWriters', 'lid' => 'dora'], 'data' => ['type' => 'ikWriters', 'lid' => 'dora', 'attributes' => ['name' => 'Dorothy']]];
        }
        $response = $this->call('POST', '/api/operations', [], [], [], [
            'HTTP_ACCEPT' => self::MEDIA_TYPE . ';ext="https://jsonapi.org/ext/atomic"',
            'CONTENT_TYPE' => self::MEDIA_TYPE . ';ext="https://jsonapi.org/ext/atomic"',
        ], (string) \json_encode(['atomic:operations' => $operations]));
        self::assertSame(200, $response->getStatusCode(), (string) $response->getContent());
        $response->assertJsonPath('atomic:results.0.data.id', $this->w('b3'));
        if ($minted) {
            $created = $response->json('atomic:results.4.data.id');
            self::assertIsString($created);
            $response->assertJsonPath('atomic:results.5.data.id', $created);
            $response->assertJsonPath('atomic:results.5.data.attributes.name', 'Dorothy');
            $this->read('/api/ikWriters/' . $created)->assertJsonPath('data.attributes.name', 'Dorothy');
            if (StoredIdForms::isUuidLike($created)) {
                self::assertSame(\strtolower($created), $created);
                $this->read('/api/ikWriters/' . \strtoupper($created))->assertJsonPath('data.id', $created);
            }
        }

        $book = $this->read($this->path('ikBooks', 'b3'));
        $book->assertJsonPath('data.attributes.title', 'Ulysses II');
        $book->assertJsonPath('data.relationships.writer.data.id', $this->w('w3'));
        self::assertSame($this->refs('l1'), $this->ids($this->path('ikBooks', 'b3', 'relationships/labels')));
        self::assertSame($this->refs('b1', 'b3'), $this->ids('/api/ikBooks'));
    }

    #[Test]
    #[Group('ext:actions')]
    public function anActionReadsItsResourceAndInputLinkageByKey(): void
    {
        $body = ['data' => ['type' => 'ikBooks', 'id' => $this->s('b1'), 'relationships' => ['writer' => ['data' => ['type' => 'ikWriters', 'id' => $this->s('w2')]]]]];
        $this->write('PATCH', $this->path('ikBooks', 'b1', '-actions/repoint'), $body)->assertOk()
            ->assertJsonPath('data.id', $this->w('b1'))
            ->assertJsonPath('data.relationships.writer.data.id', $this->w('w2'));

        $missing = ['data' => ['type' => 'ikBooks', 'id' => $this->s('b1'), 'relationships' => ['writer' => ['data' => ['type' => 'ikWriters', 'id' => $this->s('w9')]]]]];
        $this->write('PATCH', $this->path('ikBooks', 'b1', '-actions/repoint'), $missing)->assertNotFound();
        $this->write('PATCH', $this->path('ikBooks', 'w9', '-actions/repoint'), $body)->assertNotFound();
    }

    #[Test]
    public function aSelfReferencingPivotReadsEachRelationFromItsOwnSide(): void
    {
        self::assertSame($this->refs('w2'), $this->ids($this->path('ikWriters', 'w1', 'relationships/peers')));
        self::assertSame($this->refs('w3'), $this->ids($this->path('ikWriters', 'w2', 'relationships/peers')));
        self::assertSame([], $this->ids($this->path('ikWriters', 'w3', 'relationships/peers')));
        self::assertSame([], $this->ids($this->path('ikWriters', 'w1', 'relationships/followers')));
        self::assertSame($this->refs('w1'), $this->ids($this->path('ikWriters', 'w2', 'relationships/followers')));
        self::assertSame($this->refs('w3'), $this->ids($this->path('ikWriters', 'w2', 'peers')));
        self::assertSame($this->refs('w1'), $this->ids($this->path('ikWriters', 'w2', 'followers')));

        $writer = $this->read($this->path('ikWriters', 'w2') . '?include=peers,followers');
        self::assertSame($this->refs('w3'), $this->sortedIds($writer->json('data.relationships.peers.data')));
        self::assertSame($this->refs('w1'), $this->sortedIds($writer->json('data.relationships.followers.data')));
        self::assertSame($this->keys(['ikWriters', 'w1'], ['ikWriters', 'w3']), $this->included($writer));

        $writers = $this->read('/api/ikWriters?include=peers&sort=name');
        self::assertSame($this->refs('w2'), $this->sortedIds($writers->json('data.0.relationships.peers.data')));
        self::assertSame($this->refs('w3'), $this->sortedIds($writers->json('data.1.relationships.peers.data')));
        self::assertSame([], $this->sortedIds($writers->json('data.2.relationships.peers.data')));

        $nested = $this->read($this->path('ikWriters', 'w1') . '?include=peers.peers');
        self::assertSame($this->refs('w2'), $this->sortedIds($nested->json('data.relationships.peers.data')));
        self::assertSame($this->keys(['ikWriters', 'w2'], ['ikWriters', 'w3']), $this->included($nested));
        foreach ((array) $nested->json('included') as $index => $resource) {
            self::assertIsArray($resource);
            if (($resource['id'] ?? null) === $this->w('w2')) {
                self::assertSame($this->refs('w3'), $this->sortedIds($nested->json("included.{$index}.relationships.peers.data")));
            }
        }

        $counts = $this->read('/api/ikWriters?withCount=peers,followers&sort=name', self::COUNTABLE);
        foreach ([[1, 0], [1, 1], [0, 1]] as $index => [$peers, $followers]) {
            $counts->assertJsonPath("data.{$index}.relationships.peers.meta.total", $peers);
            $counts->assertJsonPath("data.{$index}.relationships.followers.meta.total", $followers);
        }

        self::assertSame($this->refs('w1'), $this->ids('/api/ikWriters?filter[peerName]=Bronte'));
        self::assertSame($this->refs('w3'), $this->ids('/api/ikWriters?filter[followerName]=Bronte'));
    }

    #[Test]
    #[Group('spec:updating-relationships')]
    public function aSelfReferencingPivotWritesOnlyTheParentsRows(): void
    {
        $peers = fn(string $ref): string => $this->path('ikWriters', $ref, 'relationships/peers');
        $member = fn(string $ref): array => ['type' => 'ikWriters', 'id' => $this->w($ref)];

        $this->write('POST', $peers('w3'), ['data' => [$member('w1')]])->assertSuccessful()->assertJsonPath('data.0.id', $this->w('w1'));
        self::assertSame($this->refs('w1'), $this->ids($peers('w3')));
        self::assertSame($this->refs('w2'), $this->ids($peers('w1')));
        $this->write('POST', $peers('w1'), ['data' => [$member('w2')]])->assertSuccessful();
        self::assertSame($this->refs('w2'), $this->ids($peers('w1')));

        $this->write('DELETE', $peers('w2'), ['data' => [$member('w3')]])->assertSuccessful();
        self::assertSame([], $this->ids($peers('w2')));
        self::assertSame($this->refs('w2'), $this->ids($peers('w1')));

        $this->write('PATCH', $peers('w1'), ['data' => [$member('w3')]])->assertSuccessful()->assertJsonPath('data.0.id', $this->w('w3'));
        self::assertSame($this->refs('w3'), $this->ids($peers('w1')));
        self::assertSame($this->refs('w1'), $this->ids($peers('w3')));

        $created = $this->write('POST', '/api/ikWriters', ['data' => [
            'type' => 'ikWriters',
            'id' => $this->w('w9'),
            'attributes' => ['name' => 'Zed'],
            'relationships' => ['peers' => ['data' => [$member('w2')]]],
        ]]);
        $created->assertCreated();
        self::assertSame($this->refs('w2'), $this->ids($peers('w9')));
        self::assertSame($this->refs('w3'), $this->ids($peers('w1')));

        if ($this->store() === 'eloquent') {
            // The witness keeps each side as its own list; the seat table has one row per seat.
            self::assertSame($this->refs('w9'), $this->ids($this->path('ikWriters', 'w2', 'followers')));
            self::assertSame($this->refs('w1'), $this->ids($this->path('ikWriters', 'w3', 'followers')));
            self::assertSame($this->refs('w3'), $this->ids($this->path('ikWriters', 'w1', 'followers')));
        }
    }

    private function w(string $ref): string
    {
        return IdKinds::wire($ref);
    }

    /**
     * The id a request names `$ref` by.
     */
    private function s(string $ref): string
    {
        return IdKinds::sent($ref);
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
        return '/api/' . $type . '/' . IdKinds::sent($ref) . ($tail !== null ? '/' . $tail : '');
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
