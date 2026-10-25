<?php

declare(strict_types=1);

namespace haddowg\JsonApiLaravel\Tests\Conformance;

use haddowg\JsonApi\Atomic\AtomicExtension;
use haddowg\JsonApiLaravel\JsonApiServiceProvider;
use haddowg\JsonApiLaravel\Tests\Fixtures\Flatten\FlattenServiceProvider;
use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Support\Facades\Gate;
use Illuminate\Testing\TestResponse;
use Orchestra\Testbench\TestCase as Orchestra;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\Test;
use Workbench\App\Models\User;

/**
 * Writes through `on()` attributes land on the related model and stay there: every test
 * re-fetches the related resource rather than trusting the write response, which renders the
 * object the write mutated in memory. Run against the in-memory witness
 * ({@see InMemoryFlattenWriteConformanceTest}) and Eloquent ({@see EloquentFlattenWriteConformanceTest}).
 *
 * The same graph checks the relation authorizer: a flattened write needs `update` on the row it
 * lands on, and a relation's read ability decides whether its linkage and included data render.
 * It also checks that a related row the caller may not `view` reads as absent wherever another
 * type renders it.
 *
 * Seed: country 1 "Wonderland"; authors 1 "Ada Lovelace" and 2 "Grace Hopper", both in country 1;
 * book 1 by and edited by author 1, book 2 by and edited by author 2, book 3 with neither.
 */
abstract class FlattenWriteConformanceTestCase extends Orchestra
{
    public const string MEDIA_TYPE = 'application/vnd.api+json';

    public const string COUNTABLE = 'application/vnd.api+json; profile="https://haddowg.github.io/json-api/profiles/countable/"';

    public const string ATOMIC_MEDIA_TYPE = 'application/vnd.api+json; ext="' . AtomicExtension::URI . '"';

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
        // Set here rather than in defineEnvironment(): the fixture provider reads it while
        // registering, which testbench runs before defineEnvironment().
        /** @var \Illuminate\Contracts\Config\Repository $config */
        $config = $app['config'];
        $config->set('flatten.store', $this->store());

        return [JsonApiServiceProvider::class, FlattenServiceProvider::class];
    }

    /**
     * @param \Illuminate\Foundation\Application $app
     */
    protected function defineEnvironment($app): void
    {
        /** @var \Illuminate\Contracts\Config\Repository $config */
        $config = $app['config'];
        $config->set('jsonapi.atomic_operations.enabled', true);
    }

    #[Test]
    #[Group('spec:crud-updating')]
    public function aFlattenedAttributeWriteMutatesTheRelatedModel(): void
    {
        $this->update('/api/books/1', ['type' => 'books', 'id' => '1', 'attributes' => ['authorName' => 'Ada King']])
            ->assertOk()
            ->assertJsonPath('data.attributes.authorName', 'Ada King');

        $this->read('/api/authors/1')->assertJsonPath('data.attributes.name', 'Ada King');
        $this->read('/api/books/1')->assertJsonPath('data.attributes.authorName', 'Ada King');
    }

    #[Test]
    #[Group('spec:crud-updating')]
    public function aMultiHopFlattenedWriteMutatesTheFinalRelatedModel(): void
    {
        $this->update('/api/books/1', ['type' => 'books', 'id' => '1', 'attributes' => ['authorCountry' => 'Oz']])
            ->assertOk()
            ->assertJsonPath('data.attributes.authorCountry', 'Oz');

        // The country is shared by both authors, so book 2 reads the new name too.
        $this->read('/api/countries/1')->assertJsonPath('data.attributes.name', 'Oz');
        $this->read('/api/books/2')->assertJsonPath('data.attributes.authorCountry', 'Oz');
        $this->read('/api/authors/1')->assertJsonPath('data.attributes.name', 'Ada Lovelace');
    }

    #[Test]
    #[Group('spec:crud-updating')]
    public function aFlattenedWriteAndAPlainAttributeWritePersistTogether(): void
    {
        $this->update('/api/books/1', ['type' => 'books', 'id' => '1', 'attributes' => ['title' => 'Notes', 'authorName' => 'Ada King', 'authorCountry' => 'Oz']])
            ->assertOk();

        $this->read('/api/books/1')
            ->assertJsonPath('data.attributes.title', 'Notes')
            ->assertJsonPath('data.attributes.authorName', 'Ada King')
            ->assertJsonPath('data.attributes.authorCountry', 'Oz');
    }

    #[Test]
    #[Group('spec:crud-updating')]
    public function aSameBodyUpdateWritesTheNewlyAssociatedRelatedModel(): void
    {
        $this->update('/api/books/1', [
            'type' => 'books',
            'id' => '1',
            'attributes' => ['editorName' => 'Renamed Editor'],
            'relationships' => ['editor' => ['data' => ['type' => 'authors', 'id' => '2']]],
        ])->assertOk();

        $this->read('/api/authors/2')->assertJsonPath('data.attributes.name', 'Renamed Editor');
        $this->read('/api/authors/1')->assertJsonPath('data.attributes.name', 'Ada Lovelace');
        $this->read('/api/books/1')->assertJsonPath('data.relationships.editor.data.id', '2');
    }

    #[Test]
    #[Group('spec:crud-creating')]
    public function aSameBodyCreateWritesTheAssociatedRelatedModel(): void
    {
        $response = $this->call('POST', '/api/books', [], [], [], $this->headers(self::MEDIA_TYPE), $this->encode([
            'data' => [
                'type' => 'books',
                'attributes' => ['title' => 'New Title', 'editorName' => 'Grace The Editor'],
                'relationships' => ['editor' => ['data' => ['type' => 'authors', 'id' => '2']]],
            ],
        ]));
        $response->assertCreated();

        $id = $response->json('data.id');
        self::assertIsString($id);
        $this->read('/api/authors/2')->assertJsonPath('data.attributes.name', 'Grace The Editor');
        $this->read('/api/books/' . $id)->assertJsonPath('data.attributes.editorName', 'Grace The Editor');
    }

    #[Test]
    #[Group('spec:crud-updating')]
    public function aFlattenedWriteOverANullRelatedModelIs422(): void
    {
        $this->update('/api/books/3', ['type' => 'books', 'id' => '3', 'attributes' => ['authorCountry' => 'Nowhere']])
            ->assertStatus(422)
            ->assertJsonPath('errors.0.code', 'RELATED_ATTRIBUTE_OWNER_MISSING')
            ->assertJsonPath('errors.0.source.pointer', '/data/attributes/authorCountry');
    }

    #[Test]
    #[Group('spec:atomic')]
    public function aFlattenedWriteInAnAtomicBatchPersists(): void
    {
        $this->atomic([
            ['op' => 'update', 'ref' => ['type' => 'books', 'id' => '1'], 'data' => ['type' => 'books', 'id' => '1', 'attributes' => ['authorName' => 'Ada King']]],
            ['op' => 'update', 'ref' => ['type' => 'books', 'id' => '2'], 'data' => ['type' => 'books', 'id' => '2', 'attributes' => ['authorCountry' => 'Oz']]],
        ])->assertOk();

        $this->read('/api/authors/1')->assertJsonPath('data.attributes.name', 'Ada King');
        $this->read('/api/countries/1')->assertJsonPath('data.attributes.name', 'Oz');
    }

    #[Test]
    #[Group('spec:atomic')]
    public function aFlattenedWriteRollsBackWithItsAtomicBatch(): void
    {
        $this->atomic([
            ['op' => 'update', 'ref' => ['type' => 'books', 'id' => '1'], 'data' => ['type' => 'books', 'id' => '1', 'attributes' => ['authorName' => 'Ada King', 'authorCountry' => 'Oz']]],
            ['op' => 'remove', 'ref' => ['type' => 'books', 'id' => '999']],
        ])->assertStatus(404);

        $this->read('/api/authors/1')->assertJsonPath('data.attributes.name', 'Ada Lovelace');
        $this->read('/api/countries/1')->assertJsonPath('data.attributes.name', 'Wonderland');
    }

    #[Test]
    #[Group('spec:crud-updating')]
    public function aFlattenedWriteOntoARelinkedRowTheCallerCannotUpdateIs403(): void
    {
        $this->denyUpdateOnAuthor('2');

        $this->update('/api/books/1', [
            'type' => 'books',
            'id' => '1',
            'attributes' => ['title' => 'Changed', 'editorName' => 'Taken Over'],
            'relationships' => ['editor' => ['data' => ['type' => 'authors', 'id' => '2']]],
        ])->assertStatus(403)->assertJsonPath('errors.0.code', 'ACCESS_DENIED');

        $this->read('/api/authors/2')->assertJsonPath('data.attributes.name', 'Grace Hopper');
        $this->read('/api/books/1')
            ->assertJsonPath('data.attributes.title', 'Algorithms')
            ->assertJsonPath('data.relationships.editor.data.id', '1');
    }

    #[Test]
    #[Group('spec:crud-creating')]
    public function aCreateWritingOntoALinkedRowTheCallerCannotUpdateIs403(): void
    {
        $this->denyUpdateOnAuthor('2');

        $this->call('POST', '/api/books', [], [], [], $this->headers(self::MEDIA_TYPE), $this->encode([
            'data' => [
                'type' => 'books',
                'attributes' => ['title' => 'New Title', 'editorName' => 'Taken Over'],
                'relationships' => ['editor' => ['data' => ['type' => 'authors', 'id' => '2']]],
            ],
        ]))->assertStatus(403);

        $this->read('/api/authors/2')->assertJsonPath('data.attributes.name', 'Grace Hopper');
        $this->read('/api/books')->assertJsonCount(3, 'data');
    }

    #[Test]
    #[Group('spec:atomic')]
    public function anAtomicFlattenedWriteOntoARelinkedRowTheCallerCannotUpdateIs403(): void
    {
        $this->denyUpdateOnAuthor('2');

        $this->atomic([
            ['op' => 'update', 'ref' => ['type' => 'books', 'id' => '1'], 'data' => [
                'type' => 'books',
                'id' => '1',
                'attributes' => ['editorName' => 'Taken Over'],
                'relationships' => ['editor' => ['data' => ['type' => 'authors', 'id' => '2']]],
            ]],
        ])->assertStatus(403);

        $this->read('/api/authors/2')->assertJsonPath('data.attributes.name', 'Grace Hopper');
        $this->read('/api/books/1')->assertJsonPath('data.relationships.editor.data.id', '1');
    }

    #[Test]
    #[Group('spec:crud-updating')]
    public function aFlattenedWriteOntoTheCurrentRowTheCallerCannotUpdateIs403(): void
    {
        $this->denyUpdateOnAuthor('1');

        $this->update('/api/books/1', ['type' => 'books', 'id' => '1', 'attributes' => ['authorName' => 'Ada King']])
            ->assertStatus(403);

        $this->read('/api/authors/1')->assertJsonPath('data.attributes.name', 'Ada Lovelace');
    }

    #[Test]
    #[Group('spec:crud-updating')]
    public function aFlattenedWriteOntoARelinkedRowTheCallerMayUpdateSucceeds(): void
    {
        // Only author 1 is off limits, and the write lands on author 2.
        $this->denyUpdateOnAuthor('1');

        $this->update('/api/books/2', [
            'type' => 'books',
            'id' => '2',
            'attributes' => ['editorName' => 'Grace The Editor'],
            'relationships' => ['editor' => ['data' => ['type' => 'authors', 'id' => '2']]],
        ])->assertOk();

        $this->read('/api/authors/2')->assertJsonPath('data.attributes.name', 'Grace The Editor');
    }

    #[Test]
    #[Group('spec:fetching')]
    public function aRelationReadAbilityDecidesWhetherAPrimaryResourceRendersItsData(): void
    {
        $this->allowAuthorDataToUsers();

        $guest = $this->read('/api/books/1?include=reviewer');
        $relationship = $guest->json('data.relationships.reviewer');
        self::assertIsArray($relationship);
        self::assertArrayHasKey('links', $relationship);
        self::assertArrayNotHasKey('data', $relationship);
        self::assertSame([], $this->includedIdentities($guest));

        $this->actingAs(new User(['id' => 1, 'name' => 'Reader']));
        $user = $this->read('/api/books/1?include=reviewer');
        $user->assertJsonPath('data.relationships.reviewer.data', ['type' => 'authors', 'id' => '1']);
        self::assertSame(['authors:1'], $this->includedIdentities($user));
    }

    #[Test]
    #[Group('spec:fetching')]
    public function aRelationReadAbilityDecidesWhetherAnIncludedResourceRendersItsData(): void
    {
        $this->allowAuthorDataToUsers();

        $guest = $this->read('/api/books?include=editor.homeCountry');
        self::assertSame(['authors:1', 'authors:2'], $this->includedIdentities($guest));
        /** @var list<array{relationships: array<string, mixed>}> $included */
        $included = $guest->json('included');
        foreach ($included as $author) {
            $relationship = $author['relationships']['homeCountry'] ?? null;
            self::assertIsArray($relationship);
            self::assertArrayHasKey('links', $relationship);
            self::assertArrayNotHasKey('data', $relationship);
        }

        $this->actingAs(new User(['id' => 1, 'name' => 'Reader']));
        $user = $this->read('/api/books?include=editor.homeCountry');
        self::assertSame(['authors:1', 'authors:2', 'countries:1'], $this->includedIdentities($user));
    }

    #[Test]
    #[Group('spec:fetching')]
    public function anIncludedToOneTheCallerMayNotViewIsLeftOut(): void
    {
        $this->denyViewOnAuthor('2');

        $books = $this->read('/api/books?include=editor');
        self::assertSame(['authors:1'], $this->includedIdentities($books));
        self::assertSame(['type' => 'authors', 'id' => '1'], $this->relationshipData($books, '1', 'editor'));
        self::assertNull($this->relationshipData($books, '2', 'editor'));

        // A flattened attribute read through the row is absent too, and the type's own
        // endpoint refuses the row.
        $this->read('/api/books/2')->assertJsonPath('data.attributes.editorName', null);
        $this->get('/api/authors/2', ['Accept' => self::MEDIA_TYPE])->assertStatus(403);
    }

    #[Test]
    #[Group('spec:fetching')]
    public function aToOneTheCallerMayNotViewIsAbsentAtItsEndpoints(): void
    {
        $this->denyViewOnAuthor('2');

        $this->read('/api/books/2/relationships/editor')->assertJsonPath('data', null);
        $this->read('/api/books/2/editor')->assertJsonPath('data', null);
    }

    #[Test]
    #[Group('spec:fetching')]
    public function aListShowsWhatItsQueryReturnsWhateverViewSays(): void
    {
        $this->denyViewOnAuthor('2');
        $both = [['type' => 'authors', 'id' => '1'], ['type' => 'authors', 'id' => '2']];

        $country = $this->read('/api/countries/1?include=authors');
        self::assertSame(['authors:1', 'authors:2'], $this->includedIdentities($country));
        $country->assertJsonPath('data.relationships.authors.data', $both);
        $this->read('/api/countries/1/relationships/authors')->assertJsonPath('data', $both);

        // A full page comes back full.
        $page = $this->read('/api/countries/1/authors?page[size]=2&page[number]=1');
        self::assertSame(['1', '2'], \array_column((array) $page->json('data'), 'id'));
    }

    #[Test]
    #[Group('spec:fetching')]
    public function everyCountMatchesTheRowsAClientCanPageThrough(): void
    {
        $this->denyViewOnAuthor('2');

        $first = $this->read('/api/countries/1/authors?page[size]=1&page[number]=1&withCount=_self_', self::COUNTABLE);
        $total = $first->json('meta.total');
        self::assertSame(2, $total);
        self::assertSame($total, $this->pagedRowCount($first));

        $count = $this->read('/api/countries/1?withCount=authors', self::COUNTABLE)->json('data.relationships.authors.meta.total');
        self::assertSame($total, $count);

        // The Relationship Queries window holds the same rows its count reports.
        $window = $this->read(
            '/api/countries/1?include=authors&withCount=authors',
            'application/vnd.api+json; profile="https://haddowg.github.io/json-api/profiles/relationship-queries/ https://haddowg.github.io/json-api/profiles/countable/"',
        );
        self::assertCount($total, (array) $window->json('data.relationships.authors.data'));
        self::assertSame($total, $window->json('data.relationships.authors.meta.total'));
    }

    #[Test]
    #[Group('spec:fetching')]
    public function aToManyOfATypeTheCallerMayNotListIsWithheld(): void
    {
        $this->denyListingAuthors();

        $country = $this->read('/api/countries/1?include=authors&withCount=authors', self::COUNTABLE);
        $relationship = $country->json('data.relationships.authors');
        self::assertIsArray($relationship);
        self::assertArrayHasKey('links', $relationship);
        self::assertArrayNotHasKey('data', $relationship);
        self::assertArrayNotHasKey('meta', $relationship);
        self::assertSame([], $this->includedIdentities($country));

        $this->get('/api/authors', ['Accept' => self::MEDIA_TYPE])->assertStatus(403);
        $this->get('/api/countries/1/authors', ['Accept' => self::MEDIA_TYPE])->assertStatus(403)->assertJsonPath('errors.0.code', 'ACCESS_DENIED');
        $this->get('/api/countries/1/relationships/authors', ['Accept' => self::MEDIA_TYPE])->assertStatus(403);

        // A single row of the type is still governed by its own `view`.
        $this->read('/api/books/1?include=editor')->assertJsonPath('data.relationships.editor.data.id', '1');
    }

    #[Test]
    #[Group('spec:fetching')]
    public function rowsTheCallerMayViewAreAllIncluded(): void
    {
        $this->denyViewOnAuthor('99');

        self::assertSame(['authors:1', 'authors:2'], $this->includedIdentities($this->read('/api/countries/1?include=authors')));
        self::assertSame(['authors:1', 'authors:2'], $this->includedIdentities($this->read('/api/books?include=editor')));
    }

    #[Test]
    #[Group('spec:fetching')]
    public function aConventionPermissionExposesAToManyOfATypeTheCallerMayNotList(): void
    {
        $this->denyListingAuthors();
        Gate::define('viewAuthors', static fn(?Authenticatable $user): bool => true);

        $this->assertListsBothAuthors('authors');
    }

    #[Test]
    #[Group('spec:fetching')]
    public function anExplicitPermissionExposesAToManyOfATypeTheCallerMayNotList(): void
    {
        $this->denyListingAuthors();
        Gate::define('seeMembers', static fn(?Authenticatable $user): bool => true);

        $this->assertListsBothAuthors('members');
    }

    #[Test]
    #[Group('spec:fetching')]
    public function aBoolPermissionSkipsTheTypesListGate(): void
    {
        $this->denyListingAuthors();

        $this->assertListsBothAuthors('openMembers');
    }

    #[Test]
    #[Group('spec:fetching')]
    public function aConventionPermissionExposesAToOneTheCallerMayNotView(): void
    {
        $this->denyViewOnAuthor('2');
        Gate::define('viewEditor', static fn(?Authenticatable $user): bool => true);

        $book = $this->read('/api/books/2?include=editor');
        $book->assertJsonPath('data.relationships.editor.data.id', '2')
            ->assertJsonPath('data.attributes.editorName', 'Grace Hopper');
        self::assertSame(['authors:2'], $this->includedIdentities($book));
        $this->read('/api/books/2/editor')->assertJsonPath('data.id', '2');
        $this->read('/api/books/2/relationships/editor')->assertJsonPath('data.id', '2');
    }

    #[Test]
    #[Group('spec:fetching')]
    public function anExplicitPermissionExposesAToOneTheCallerMayNotView(): void
    {
        $this->denyViewOnAuthor('2');
        $this->allowAuthorDataToUsers();
        $this->actingAs(new User(['id' => 1, 'name' => 'Reader']));

        $book = $this->read('/api/books/2?include=reviewer');
        $book->assertJsonPath('data.relationships.reviewer.data.id', '2');
        self::assertSame(['authors:2'], $this->includedIdentities($book));
        $this->read('/api/books/2/reviewer')->assertJsonPath('data.id', '2');
    }

    #[Test]
    #[Group('spec:fetching')]
    public function aDeclaredPermissionBlocksAToManyOfATypeTheCallerMayList(): void
    {
        Gate::define('viewAuthors', static fn(?Authenticatable $user): bool => false);

        $country = $this->read('/api/countries/1?include=authors&withCount=authors', self::COUNTABLE);
        $this->assertWithheld($country, 'authors');
        self::assertSame([], $this->includedIdentities($country));
        $this->get('/api/countries/1/authors', ['Accept' => self::MEDIA_TYPE])->assertStatus(403);
        $this->get('/api/countries/1/relationships/authors', ['Accept' => self::MEDIA_TYPE])->assertStatus(403);
        $this->read('/api/authors')->assertJsonCount(2, 'data');
    }

    #[Test]
    #[Group('spec:fetching')]
    public function aDeclaredPermissionBlocksAToOneTheCallerMayView(): void
    {
        Gate::define('viewEditor', static fn(?Authenticatable $user): bool => false);

        $book = $this->read('/api/books/1?include=editor');
        $this->assertWithheld($book, 'editor');
        self::assertSame([], $this->includedIdentities($book));
        $book->assertJsonPath('data.attributes.editorName', null);
        $this->get('/api/books/1/editor', ['Accept' => self::MEDIA_TYPE])->assertStatus(403);
        $this->get('/api/books/1/relationships/editor', ['Accept' => self::MEDIA_TYPE])->assertStatus(403);

        $this->update('/api/books/1', ['type' => 'books', 'id' => '1', 'attributes' => ['editorName' => 'Blocked']])
            ->assertStatus(422)
            ->assertJsonPath('errors.0.code', 'RELATED_ATTRIBUTE_OWNER_MISSING');
        $this->read('/api/authors/1')->assertJsonPath('data.attributes.name', 'Ada Lovelace');
    }

    #[Test]
    #[Group('spec:fetching')]
    public function aMissingConventionAbilityFallsBackToTheTypesGates(): void
    {
        // Nothing defines `viewAuthors` or `viewEditor`, so the authors type's own gates decide.
        $this->denyListingAuthors();
        $this->denyViewOnAuthor('2');

        $this->assertWithheld($this->read('/api/countries/1?include=authors'), 'authors');
        $this->read('/api/books/2?include=editor')->assertJsonPath('data.relationships.editor.data', null);
    }

    /**
     * Asserts `$relation` on country 1 lists both authors everywhere it renders, with exact counts.
     */
    protected function assertListsBothAuthors(string $relation): void
    {
        $both = [['type' => 'authors', 'id' => '1'], ['type' => 'authors', 'id' => '2']];

        $country = $this->read('/api/countries/1?include=' . $relation . '&withCount=' . $relation, self::COUNTABLE);
        $country->assertJsonPath('data.relationships.' . $relation . '.data', $both)
            ->assertJsonPath('data.relationships.' . $relation . '.meta.total', 2);
        self::assertSame(['authors:1', 'authors:2'], $this->includedIdentities($country));

        $this->read('/api/countries/1/relationships/' . $relation)->assertJsonPath('data', $both);
        $related = $this->read('/api/countries/1/' . $relation . '?withCount=_self_', self::COUNTABLE);
        self::assertSame(['1', '2'], \array_column((array) $related->json('data'), 'id'));
        $related->assertJsonPath('meta.total', 2);

        $window = $this->read(
            '/api/countries/1?include=' . $relation . '&withCount=' . $relation,
            'application/vnd.api+json; profile="https://haddowg.github.io/json-api/profiles/relationship-queries/ https://haddowg.github.io/json-api/profiles/countable/"',
        );
        $window->assertJsonPath('data.relationships.' . $relation . '.data', $both)
            ->assertJsonPath('data.relationships.' . $relation . '.meta.total', 2);
    }

    /**
     * Asserts `$relation` on the primary resource renders only its links.
     *
     * @param TestResponse<\Symfony\Component\HttpFoundation\Response> $response
     */
    protected function assertWithheld(TestResponse $response, string $relation): void
    {
        $relationship = $response->json('data.relationships.' . $relation);
        self::assertIsArray($relationship);
        self::assertArrayHasKey('links', $relationship);
        self::assertArrayNotHasKey('data', $relationship);
        self::assertArrayNotHasKey('meta', $relationship);
    }

    #[Test]
    #[Group('spec:fetching')]
    public function aRelatedPagesItemsShowTheirFilteredRelationsEverywhere(): void
    {
        $accept = 'application/vnd.api+json; profile="https://haddowg.github.io/json-api/profiles/relationship-queries/ https://haddowg.github.io/json-api/profiles/countable/"';

        // Only Ada's "Algorithms" matches.
        $related = $this->read('/api/countries/1/authors?include=books&withCount=books&relatedQuery[books][filter][title]=Alg', $accept);
        self::assertSame([['type' => 'books', 'id' => '1']], $this->relationshipData($related, '1', 'books'));
        self::assertSame([], $this->relationshipData($related, '2', 'books'));
        self::assertSame(['books:1'], $this->includedIdentities($related));
        $related->assertJsonPath('data.0.relationships.books.meta.total', 1)
            ->assertJsonPath('data.1.relationships.books.meta.total', 0);

        // A to-one related endpoint: Ada edits book 1, and "Compilers" isn't hers.
        $editor = $this->read('/api/books/1/editor?include=books&withCount=books&relatedQuery[books][filter][title]=Comp', $accept);
        $editor->assertJsonPath('data.relationships.books.data', [])
            ->assertJsonPath('data.relationships.books.meta.total', 0);
        self::assertSame([], $this->includedIdentities($editor));

        // The relationship endpoint renders identifiers only, so its members carry no
        // relationships or included resources that could disagree.
        $linkage = $this->read('/api/countries/1/relationships/authors?include=books', $accept);
        self::assertSame([['type' => 'authors', 'id' => '1'], ['type' => 'authors', 'id' => '2']], $linkage->json('data'));
        self::assertNull($linkage->json('included'));
    }

    /**
     * Refuses `viewAny` on authors and allows it everywhere else.
     */
    protected function denyListingAuthors(): void
    {
        Gate::define('viewAny', static function (?Authenticatable $user, mixed $subject = null): bool {
            $class = \is_object($subject) ? $subject::class : (\is_string($subject) ? $subject : '');

            return !\str_starts_with(\class_basename($class), 'Author');
        });
    }

    /**
     * Follows `links.next` from `$page` to the end and counts every row on the way.
     *
     * @param TestResponse<\Symfony\Component\HttpFoundation\Response> $page
     */
    protected function pagedRowCount(TestResponse $page): int
    {
        $rows = 0;
        for ($pages = 0; $pages < 20; ++$pages) {
            $rows += \count((array) $page->json('data'));
            /** @var mixed $next */
            $next = $page->json('links.next.href') ?? $page->json('links.next');
            if (!\is_string($next)) {
                return $rows;
            }
            $page = $this->read((string) \preg_replace('#^https?://[^/]+#', '', $next), self::COUNTABLE);
        }

        self::fail('The pages never ended.');
    }

    /**
     * Lets `view` pass on everything except the author with `$id`.
     */
    protected function denyViewOnAuthor(string $id): void
    {
        Gate::define('view', static function (?Authenticatable $user, mixed $subject = null) use ($id): bool {
            if (!\is_object($subject) || !\str_starts_with((new \ReflectionClass($subject))->getShortName(), 'Author')) {
                return true;
            }
            /** @var mixed $subjectId */
            $subjectId = $subject->id ?? null;

            return !\is_scalar($subjectId) || (string) $subjectId !== $id;
        });
    }

    /**
     * The `data` of `$relationship` on the primary resource with `$id` in a collection document.
     *
     * @param TestResponse<\Symfony\Component\HttpFoundation\Response> $response
     */
    protected function relationshipData(TestResponse $response, string $id, string $relationship): mixed
    {
        /** @var list<array{id: string, relationships: array<string, array<string, mixed>>}> $resources */
        $resources = $response->json('data');
        foreach ($resources as $resource) {
            if ($resource['id'] === $id) {
                self::assertArrayHasKey('data', $resource['relationships'][$relationship] ?? []);

                return $resource['relationships'][$relationship]['data'];
            }
        }

        self::fail('No resource with id ' . $id);
    }

    /**
     * Lets `update` pass on everything except the author with `$id`.
     */
    protected function denyUpdateOnAuthor(string $id): void
    {
        Gate::define('update', static function (?Authenticatable $user, mixed $subject = null) use ($id): bool {
            if (!\is_object($subject) || !\str_starts_with((new \ReflectionClass($subject))->getShortName(), 'Author')) {
                return true;
            }
            /** @var mixed $subjectId */
            $subjectId = $subject->id ?? null;

            return !\is_scalar($subjectId) || (string) $subjectId !== $id;
        });
    }

    /**
     * Lets the `seeAuthorData` read ability pass for an authenticated caller only.
     */
    protected function allowAuthorDataToUsers(): void
    {
        Gate::define('seeAuthorData', static fn(?Authenticatable $user): bool => $user !== null);
    }

    /**
     * The sorted `type:id` of each included resource.
     *
     * @param TestResponse<\Symfony\Component\HttpFoundation\Response> $response
     *
     * @return list<string>
     */
    protected function includedIdentities(TestResponse $response): array
    {
        /** @var list<array{type: string, id: string}> $included */
        $included = $response->json('included') ?? [];
        $identities = \array_map(static fn(array $resource): string => $resource['type'] . ':' . $resource['id'], $included);
        \sort($identities);

        return $identities;
    }

    /**
     * @param array<string, mixed> $data
     *
     * @return TestResponse<\Symfony\Component\HttpFoundation\Response>
     */
    protected function update(string $uri, array $data): TestResponse
    {
        return $this->call('PATCH', $uri, [], [], [], $this->headers(self::MEDIA_TYPE), $this->encode(['data' => $data]));
    }

    /**
     * @return TestResponse<\Symfony\Component\HttpFoundation\Response>
     */
    protected function read(string $uri, string $accept = self::MEDIA_TYPE): TestResponse
    {
        return $this->get($uri, ['Accept' => $accept])->assertOk();
    }

    /**
     * @param list<array<string, mixed>> $operations
     *
     * @return TestResponse<\Symfony\Component\HttpFoundation\Response>
     */
    protected function atomic(array $operations): TestResponse
    {
        return $this->call('POST', '/api/operations', [], [], [], $this->headers(self::ATOMIC_MEDIA_TYPE), $this->encode(['atomic:operations' => $operations]));
    }

    /**
     * @return array<string, string>
     */
    private function headers(string $mediaType): array
    {
        return ['CONTENT_TYPE' => $mediaType, 'HTTP_ACCEPT' => $mediaType];
    }

    /**
     * @param array<string, mixed> $document
     */
    private function encode(array $document): string
    {
        return \json_encode($document, \JSON_THROW_ON_ERROR);
    }
}
