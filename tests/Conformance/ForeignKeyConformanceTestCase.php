<?php

declare(strict_types=1);

namespace haddowg\JsonApiLaravel\Tests\Conformance;

use haddowg\JsonApi\Atomic\AtomicExtension;
use haddowg\JsonApiLaravel\JsonApiServiceProvider;
use haddowg\JsonApiLaravel\Testing\InteractsWithJsonApi;
use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Support\Facades\Gate;
use Illuminate\Testing\TestResponse;
use Orchestra\Testbench\TestCase as Orchestra;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\Test;

/**
 * A relation declared with a foreign key reads the related rows whose foreign key holds the
 * parent's id, on every read path, whatever serves the parent: `fk-users` and the `/inbox`
 * singleton come from a custom provider holding none of their rows, and `fk-teams` is a type
 * whose model declares no relation to its members. Its relationship writes set or clear the
 * key on the related rows. `fk-grants` and `fk-revisable-grants`, also provider-served, take
 * write-only secrets under no foreign key, and reading what a grant holds of them throws, so
 * each write path that sets them shows whether it reads the current members. Asserted identically on the in-memory
 * ({@see InMemoryForeignKeyConformanceTest}) and Eloquent
 * ({@see EloquentForeignKeyConformanceTest}) wirings over
 * {@see \Workbench\App\ForeignKey\ForeignKeySeed}.
 */
#[Group('spec:fetching-relationships')]
#[Group('spec:fetching-includes')]
abstract class ForeignKeyConformanceTestCase extends Orchestra
{
    use InteractsWithJsonApi;

    protected const string BASE_URI = 'https://example.test/api';

    protected const string COUNTABLE_PROFILE = 'https://haddowg.github.io/json-api/profiles/countable/';

    protected const string RELATIONSHIP_QUERIES_PROFILE = 'https://haddowg.github.io/json-api/profiles/relationship-queries/';

    private const string ATOMIC_MEDIA_TYPE = 'application/vnd.api+json; ext="' . AtomicExtension::URI . '"';

    /**
     * @return class-string
     */
    abstract protected function conformanceServiceProvider(): string;

    /**
     * @param \Illuminate\Foundation\Application $app
     *
     * @return array<int, class-string>
     */
    protected function getPackageProviders($app): array
    {
        return [JsonApiServiceProvider::class, $this->conformanceServiceProvider()];
    }

    /**
     * @param \Illuminate\Foundation\Application $app
     */
    protected function defineEnvironment($app): void
    {
        /** @var \Illuminate\Contracts\Config\Repository $config */
        $config = $app['config'];
        $config->set('jsonapi.atomic_operations.enabled', true);
        $config->set('jsonapi.base_uri', self::BASE_URI);
        $config->set('logging.default', 'null');
    }

    protected function seedConformanceData(): void {}

    protected function setUp(): void
    {
        parent::setUp();
        $this->seedConformanceData();
    }

    #[Test]
    public function anActionTakesARelatedOnlyInputWhoseToManyNoRequestReads(): void
    {
        $response = $this->jsonApi()
            ->withData(['type' => 'fk-imports', 'relationships' => ['secrets' => ['data' => [['type' => 'fk-secrets', 'id' => '1']]]]])
            ->post('/api/fk-users/ada/-actions/import')
            ->assertOk();

        $data = $this->decode($response)['data'] ?? null;
        self::assertIsArray($data);
        self::assertSame('ada', $data['id'] ?? null);
    }

    #[Test]
    public function aCreateSetsAWriteOnlyToManyWithoutReadingItsMembers(): void
    {
        $response = $this->jsonApi()
            ->withData(['type' => 'fk-grants', 'relationships' => ['secrets' => ['data' => [['type' => 'fk-secrets', 'id' => '1']]]]])
            ->post('/api/fk-grants')
            ->assertCreated();

        $grant = $this->decode($response)['data'] ?? null;
        self::assertSame(['1'], $this->attribute($grant, 'received'));
        self::assertIsArray($grant);
        self::assertArrayNotHasKey('secrets', \is_array($grant['relationships'] ?? null) ? $grant['relationships'] : []);

        $read = $this->fetch('/fk-grants/g2')['data'] ?? null;
        self::assertSame(['1'], $this->attribute($read, 'received'));
    }

    #[Test]
    public function noQueryOnACreateReachesAWriteOnlyToMany(): void
    {
        $document = ['type' => 'fk-grants', 'relationships' => ['secrets' => ['data' => [['type' => 'fk-secrets', 'id' => '1']]]]];
        foreach (['include=secrets', 'withCount=secrets', 'relatedQuery[secrets][sort]=label'] as $query) {
            $this->jsonApi()->withProfile(self::COUNTABLE_PROFILE, self::RELATIONSHIP_QUERIES_PROFILE)->withData($document)->post('/api/fk-grants?' . $query)->assertStatus(400);
        }

        $sparse = $this->jsonApi()->withData($document)->post('/api/fk-grants?fields[fk-grants]=secrets')->assertCreated();
        $grant = $this->decode($sparse)['data'] ?? null;
        self::assertIsArray($grant);
        self::assertArrayNotHasKey('relationships', $grant);

        $this->jsonApi()->get('/api/fk-grants/g1/relationships/secrets')->assertNotFound();
        $this->jsonApi()->get('/api/fk-grants/g1/secrets')->assertNotFound();
    }

    #[Test]
    #[Group('ext:atomic')]
    public function anAtomicAddSetsAWriteOnlyToManyWithoutReadingItsMembers(): void
    {
        $response = $this->atomic([
            ['op' => 'add', 'data' => ['type' => 'fk-grants', 'relationships' => ['secrets' => ['data' => [['type' => 'fk-secrets', 'id' => '1']]]]]],
        ]);
        $response->assertOk();

        self::assertSame(['1'], $this->attribute($this->fetch('/fk-grants/g2')['data'] ?? null, 'received'));
    }

    #[Test]
    public function anActionHydratesAWriteOnlyToManyOfItsInputWithoutReadingItsMembers(): void
    {
        $response = $this->jsonApi()
            ->withData(['type' => 'fk-grants', 'relationships' => ['secrets' => ['data' => [['type' => 'fk-secrets', 'id' => '1']]]]])
            ->post('/api/fk-grants/g1/-actions/stamp')
            ->assertOk();

        // The stamp action refuses an input whose secrets aren't the related models, so this
        // also shows the input holds them, as a loaded relation on the Eloquent grant.
        self::assertSame(['1'], $this->attribute($this->decode($response)['data'] ?? null, 'received'));
    }

    #[Test]
    public function aCollectionActionsInputHoldsAnEmptyLockedToMany(): void
    {
        $response = $this->jsonApi()
            ->withData(['type' => 'fk-revisable-grants', 'id' => 'g1', 'relationships' => ['lockedSecrets' => ['data' => []]]])
            ->patch('/api/fk-revisable-grants/-actions/revise-any')
            ->assertOk();

        self::assertSame(['received' => []], $this->decode($response)['meta'] ?? null);
    }

    #[Test]
    public function anActionsInputNamingAMissingMemberIsNotFound(): void
    {
        $this->jsonApi()
            ->withData(['type' => 'fk-grants', 'relationships' => ['secrets' => ['data' => [['type' => 'fk-secrets', 'id' => '999']]]]])
            ->post('/api/fk-grants/g1/-actions/stamp')
            ->assertNotFound();
    }

    #[Test]
    public function aPatchReplacesAWriteOnlyToManyWithoutReadingItsMembers(): void
    {
        $response = $this->jsonApi()
            ->withData(['type' => 'fk-revisable-grants', 'id' => 'g1', 'relationships' => ['secrets' => ['data' => [['type' => 'fk-secrets', 'id' => '1']]]]])
            ->patch('/api/fk-revisable-grants/g1')
            ->assertOk();

        self::assertSame(['1'], $this->attribute($this->decode($response)['data'] ?? null, 'received'));
    }

    #[Test]
    public function aPatchOfAToManyThatCantChangeMembershipComparesTheMembersTheParentHolds(): void
    {
        // The grant holds secrets 1 and 2, so naming only 1 changes its members.
        $this->jsonApi()
            ->withData(['type' => 'fk-revisable-grants', 'id' => 'g1', 'relationships' => ['lockedSecrets' => ['data' => [['type' => 'fk-secrets', 'id' => '1']]]]])
            ->patch('/api/fk-revisable-grants/g1')
            ->assertForbidden();
    }

    #[Test]
    public function aPatchActionNamingTheMembersOfALockedWriteOnlyToManyIsAccepted(): void
    {
        // The input is compared with g2, the grant the action was called on, which holds
        // secret 1.
        $response = $this->revise('/fk-revisable-grants/g2/-actions/revise', ['1'])->assertOk();

        self::assertSame(['1'], $this->attribute($this->decode($response)['data'] ?? null, 'received'));
    }

    #[Test]
    public function aPatchActionAddingAMemberToALockedWriteOnlyToManyIsForbidden(): void
    {
        $this->revise('/fk-revisable-grants/g2/-actions/revise', ['1', '2'])
            ->assertForbidden()
            ->assertJsonPath('errors.0.code', 'MEMBERSHIP_CHANGE_PROHIBITED');
    }

    #[Test]
    public function aPatchActionRemovingAMemberFromALockedWriteOnlyToManyIsForbidden(): void
    {
        $this->revise('/fk-revisable-grants/g2/-actions/revise', [])
            ->assertForbidden()
            ->assertJsonPath('errors.0.code', 'MEMBERSHIP_CHANGE_PROHIBITED');
    }

    #[Test]
    public function aCollectionPatchActionComparesALockedWriteOnlyToManyWithNoMembers(): void
    {
        // A collection action has no grant, so naming the secret g2 holds still changes the
        // empty set it compares with, though the document names g2.
        $this->revise('/fk-revisable-grants/-actions/revise-any', ['1'])
            ->assertForbidden()
            ->assertJsonPath('errors.0.code', 'MEMBERSHIP_CHANGE_PROHIBITED');
    }

    #[Test]
    public function aPatchActionWhoseInputIsAnotherTypeComparesALockedToManyWithNoMembers(): void
    {
        // The action is called on a user, so g2, the grant its input names, isn't read.
        $this->revise('/fk-users/ada/-actions/revise-grant', ['1'])
            ->assertForbidden()
            ->assertJsonPath('errors.0.code', 'MEMBERSHIP_CHANGE_PROHIBITED');
        $this->revise('/fk-users/ada/-actions/revise-grant', [])->assertOk()->assertJsonPath('meta', ['received' => []]);
    }

    #[Test]
    public function aPatchActionsOwnInputTakesALockedToManyUnchecked(): void
    {
        // The handler builds its own input, which is no stored grant, so it decides what a
        // change of the members means. g2 holds secret 1.
        $this->revise('/fk-revisable-grants/g2/-actions/revise-command', ['2', '1'])
            ->assertOk()
            ->assertJsonPath('meta', ['received' => ['2', '1']]);
    }

    #[Test]
    public function anActionComparesALockedToManyWithTheItemsTheForeignKeyReads(): void
    {
        // As a PATCH of ada does, the action reads the items holding ada's key.
        $revise = fn(string ...$ids): TestResponse => $this->jsonApi()
            ->withData(['type' => 'fk-users', 'id' => 'ada', 'relationships' => ['lockedItems' => ['data' => \array_map(static fn(string $id): array => ['type' => 'fk-items', 'id' => $id], $ids)]]])
            ->patch('/api/fk-users/ada/-actions/revise');

        // FkUser declares lockedItems, so its input holds the items themselves.
        $revise('5', '4', '3', '2', '1')->assertOk()->assertJsonPath('meta', ['revised' => true, 'lockedItems' => ['5', '4', '3', '2', '1']]);
        $revise('1')->assertForbidden()->assertJsonPath('errors.0.code', 'MEMBERSHIP_CHANGE_PROHIBITED');
        $revise('1', '2', '3', '4', '5', '6')->assertForbidden();
    }

    #[Test]
    #[Group('spec:actions')]
    public function anActionInputWithNoPropertyForAForeignKeyToManyLeavesItsLinkageToTheHandler(): void
    {
        $dynamic = [];
        \set_error_handler(static function (int $level, string $message) use (&$dynamic): bool {
            if (!\str_contains($message, 'dynamic property')) {
                return false;
            }
            $dynamic[] = $message;

            return true;
        }, \E_DEPRECATED);
        try {
            $regroup = fn(string ...$ids): TestResponse => $this->jsonApi()
                ->withData(['type' => 'fk-teams', 'id' => '1', 'relationships' => ['members' => ['data' => \array_map(static fn(string $id): array => ['type' => 'fk-members', 'id' => $id], $ids)]]])
                ->patch('/api/fk-teams/1/-actions/regroup');
            $response = $regroup('4', '1');
            $missing = $regroup('99');
        } finally {
            \restore_error_handler();
        }

        $response->assertOk()->assertJsonPath('meta', ['members' => ['4', '1']]);
        self::assertSame([], $dynamic);
        $missing->assertNotFound();
        self::assertSame(['1', '2', '3'], $this->ids($this->fetch('/fk-teams/1/members?page[size]=10')['data'] ?? null));
    }

    #[Test]
    public function aPlainReadOfAProviderBackedParentRenders(): void
    {
        $document = $this->fetch('/fk-users/ada');

        self::assertSame('Ada', $this->attribute($document['data'] ?? null, 'name'));
        self::assertSame(['1', '2'], $this->ids($this->relationship($document['data'] ?? null, 'featuredItems')['data'] ?? null));
    }

    #[Test]
    public function theRelatedEndpointServesTheRowsHoldingTheParentsKey(): void
    {
        $document = $this->fetch('/fk-users/ada/items');

        self::assertSame(['1', '2'], $this->ids($document['data'] ?? null));
        self::assertSame(self::BASE_URI . '/fk-users/ada/items?page%5Bnumber%5D=2&page%5Bsize%5D=2', $this->links($document)['next'] ?? null);
        self::assertSame([], $this->ids($this->fetch('/fk-users/cy/items')['data'] ?? null));
    }

    #[Test]
    public function anOffsetPagePastTheFirstServesTheNextRows(): void
    {
        self::assertSame(['3', '4'], $this->ids($this->fetch('/fk-users/ada/items?page[number]=2&page[size]=2')['data'] ?? null));
        self::assertSame(['5'], $this->ids($this->fetch('/fk-users/ada/items?page[number]=3&page[size]=2')['data'] ?? null));
    }

    #[Test]
    public function theRelatedEndpointFiltersAndSortsTheRows(): void
    {
        self::assertSame(['3'], $this->ids($this->fetch('/fk-users/ada/items?filter[title]=Three')['data'] ?? null));
        self::assertSame(['2', '3'], $this->ids($this->fetch('/fk-users/ada/items?sort=-title')['data'] ?? null));
    }

    #[Test]
    public function aCountedRelatedPageCarriesItsTotal(): void
    {
        $document = $this->fetch('/fk-users/ada/items?withCount=_self_', [self::COUNTABLE_PROFILE]);

        self::assertSame(['1', '2'], $this->ids($document['data'] ?? null));
        self::assertSame(5, $this->meta($document)['total'] ?? null);
    }

    #[Test]
    public function theRelationshipEndpointServesTheSameRows(): void
    {
        $document = $this->fetch('/fk-users/ada/relationships/items');

        self::assertSame(['1', '2'], $this->ids($document['data'] ?? null));
        self::assertSame(['6'], $this->ids($this->fetch('/fk-users/bob/relationships/items')['data'] ?? null));
    }

    #[Test]
    public function anIncludeRendersTheFirstPage(): void
    {
        $document = $this->fetch('/fk-users/ada?include=items');
        $items = $this->relationship($document['data'] ?? null, 'items');

        self::assertSame(['1', '2'], $this->ids($items['data'] ?? null));
        self::assertSame(['fk-items:1', 'fk-items:2'], $this->includedKeys($document));
        self::assertSame(self::BASE_URI . '/fk-users/ada/relationships/items?page%5Bnumber%5D=2&page%5Bsize%5D=2', $this->links($items)['next'] ?? null);
    }

    #[Test]
    public function aCollectionIncludeCutsEachParentsFirstPage(): void
    {
        $document = $this->fetch('/fk-users?include=items');
        $users = $document['data'] ?? null;
        self::assertIsArray($users);

        self::assertSame(['1', '2'], $this->ids($this->relationship($users[0] ?? null, 'items')['data'] ?? null));
        self::assertSame(['6'], $this->ids($this->relationship($users[1] ?? null, 'items')['data'] ?? null));
        self::assertSame([], $this->ids($this->relationship($users[2] ?? null, 'items')['data'] ?? null));
        self::assertSame(['fk-items:1', 'fk-items:2', 'fk-items:6'], $this->includedKeys($document));
    }

    #[Test]
    public function withDataLinkageRendersTheFirstPageOnAPlainRead(): void
    {
        $document = $this->fetch('/fk-users');
        $users = $document['data'] ?? null;
        self::assertIsArray($users);

        self::assertSame(['1', '2'], $this->ids($this->relationship($users[0] ?? null, 'featuredItems')['data'] ?? null));
        self::assertSame(['6'], $this->ids($this->relationship($users[1] ?? null, 'featuredItems')['data'] ?? null));
        self::assertArrayNotHasKey('included', $document);
    }

    #[Test]
    public function withCountTotalsEachParentsRows(): void
    {
        $document = $this->fetch('/fk-users?withCount=items', [self::COUNTABLE_PROFILE]);
        $users = $document['data'] ?? null;
        self::assertIsArray($users);

        self::assertSame(['total' => 5], $this->relationship($users[0] ?? null, 'items')['meta'] ?? null);
        self::assertSame(['total' => 1], $this->relationship($users[1] ?? null, 'items')['meta'] ?? null);
        self::assertSame(['total' => 0], $this->relationship($users[2] ?? null, 'items')['meta'] ?? null);
    }

    #[Test]
    public function aCursorPageAndItsNextAreBoundedByTheForeignKey(): void
    {
        $first = $this->fetch('/fk-users/ada/cursorItems');
        self::assertSame(['1', '2'], $this->ids($first['data'] ?? null));

        $next = $this->links($first)['next'] ?? null;
        self::assertIsString($next);
        self::assertSame(['3', '4'], $this->ids($this->fetch(\substr($next, \strlen(self::BASE_URI)))['data'] ?? null));

        $included = $this->fetch('/fk-users?include=cursorItems');
        $users = $included['data'] ?? null;
        self::assertIsArray($users);
        self::assertSame(['1', '2'], $this->ids($this->relationship($users[0] ?? null, 'cursorItems')['data'] ?? null));
        self::assertSame(['6'], $this->ids($this->relationship($users[1] ?? null, 'cursorItems')['data'] ?? null));
    }

    #[Test]
    public function aRelatedQueryReordersAndNarrowsTheIncludedPage(): void
    {
        $sorted = $this->fetch('/fk-users/ada?include=items&relatedQuery[items][sort]=-title', [self::RELATIONSHIP_QUERIES_PROFILE]);
        self::assertSame(['2', '3'], $this->ids($this->relationship($sorted['data'] ?? null, 'items')['data'] ?? null));

        $filtered = $this->fetch('/fk-users/ada?include=items&relatedQuery[items][filter][title]=Four', [self::RELATIONSHIP_QUERIES_PROFILE]);
        self::assertSame(['4'], $this->ids($this->relationship($filtered['data'] ?? null, 'items')['data'] ?? null));
    }

    #[Test]
    public function theRelatedTypesFilterDefaultsApplyOnEveryPath(): void
    {
        self::assertSame(['1'], $this->ids($this->fetch('/fk-users/ada/notes')['data'] ?? null));
        self::assertSame(['1'], $this->ids($this->fetch('/fk-users/ada/relationships/notes')['data'] ?? null));
        self::assertSame(['2'], $this->ids($this->fetch('/fk-users/ada/notes?filter[archived]=true')['data'] ?? null));

        $included = $this->fetch('/fk-users?include=notes');
        $users = $included['data'] ?? null;
        self::assertIsArray($users);
        self::assertSame(['1'], $this->ids($this->relationship($users[0] ?? null, 'notes')['data'] ?? null));
        self::assertSame(['3'], $this->ids($this->relationship($users[1] ?? null, 'notes')['data'] ?? null));
        self::assertSame(['fk-notes:1', 'fk-notes:3'], $this->includedKeys($included));

        $counted = $this->fetch('/fk-users/ada?withCount=notes', [self::COUNTABLE_PROFILE]);
        self::assertSame(['total' => 1], $this->relationship($counted['data'] ?? null, 'notes')['meta'] ?? null);
    }

    #[Test]
    public function aHasOneReadsItsTargetThroughTheForeignKey(): void
    {
        $profile = $this->fetch('/fk-users/ada/profile');
        self::assertSame('1', \is_array($profile['data'] ?? null) ? ($profile['data']['id'] ?? null) : null);
        $none = $this->fetch('/fk-users/bob/profile');
        self::assertArrayHasKey('data', $none);
        self::assertNull($none['data']);

        $linkage = $this->fetch('/fk-users/ada/relationships/profile');
        self::assertSame(['type' => 'fk-profiles', 'id' => '1'], $linkage['data'] ?? null);

        $included = $this->fetch('/fk-users?include=profile');
        $users = $included['data'] ?? null;
        self::assertIsArray($users);
        self::assertSame(['type' => 'fk-profiles', 'id' => '1'], $this->relationship($users[0] ?? null, 'profile')['data'] ?? null);
        $bob = $this->relationship($users[1] ?? null, 'profile');
        self::assertArrayHasKey('data', $bob);
        self::assertNull($bob['data']);
        self::assertSame(['fk-profiles:1'], $this->includedKeys($included));
    }

    #[Test]
    public function aProviderBackedSingletonReadsThroughTheForeignKey(): void
    {
        $document = $this->fetch('/inbox?include=items');
        self::assertSame(['1', '2'], $this->ids($this->relationship($document['data'] ?? null, 'items')['data'] ?? null));

        self::assertSame(['1', '2'], $this->ids($this->fetch('/inbox/items')['data'] ?? null));
        self::assertSame(['1', '2'], $this->ids($this->fetch('/inbox/relationships/items')['data'] ?? null));
    }

    #[Test]
    public function aParentWithNoInverseRelationReadsThroughTheForeignKey(): void
    {
        self::assertSame(['1', '2'], $this->ids($this->fetch('/fk-teams/1/members')['data'] ?? null));
        self::assertSame(['3'], $this->ids($this->fetch('/fk-teams/1/members?page[number]=2&page[size]=2')['data'] ?? null));
        self::assertSame(['4'], $this->ids($this->fetch('/fk-teams/2/relationships/members')['data'] ?? null));

        $document = $this->fetch('/fk-teams?include=members&withCount=members', [self::COUNTABLE_PROFILE]);
        $teams = $document['data'] ?? null;
        self::assertIsArray($teams);
        $red = $this->relationship($teams[0] ?? null, 'members');
        self::assertSame(['1', '2'], $this->ids($red['data'] ?? null));
        self::assertSame(['total' => 3], $red['meta'] ?? null);
        self::assertSame(['4'], $this->ids($this->relationship($teams[1] ?? null, 'members')['data'] ?? null));
        self::assertSame(['fk-members:1', 'fk-members:2', 'fk-members:4'], $this->includedKeys($document));
    }

    #[Test]
    public function aToManyWithNoForeignKeyIsReadOffTheModelAndCutInMemory(): void
    {
        self::assertSame(['5', '4'], $this->ids($this->fetch('/fk-users/ada/pinned')['data'] ?? null));
        self::assertSame(['3'], $this->ids($this->fetch('/fk-users/ada/pinned?page[number]=2&page[size]=2')['data'] ?? null));
        self::assertSame(['5', '4'], $this->ids($this->fetch('/fk-users/ada/relationships/pinned')['data'] ?? null));

        $document = $this->fetch('/fk-users?include=pinned');
        $users = $document['data'] ?? null;
        self::assertIsArray($users);
        self::assertSame(['5', '4'], $this->ids($this->relationship($users[0] ?? null, 'pinned')['data'] ?? null));
        self::assertSame([], $this->ids($this->relationship($users[1] ?? null, 'pinned')['data'] ?? null));
        self::assertSame(['fk-items:4', 'fk-items:5'], $this->includedKeys($document));

        $counted = $this->fetch('/fk-users?withCount=pinned', [self::COUNTABLE_PROFILE]);
        $users = $counted['data'] ?? null;
        self::assertIsArray($users);
        self::assertSame(['total' => 3], $this->relationship($users[0] ?? null, 'pinned')['meta'] ?? null);
        self::assertSame(['total' => 0], $this->relationship($users[1] ?? null, 'pinned')['meta'] ?? null);
    }

    #[Test]
    public function aHasOneWithNoForeignKeyReadsTheModelsTarget(): void
    {
        $favourite = $this->fetch('/fk-users/ada/favourite');
        self::assertSame('2', \is_array($favourite['data'] ?? null) ? ($favourite['data']['id'] ?? null) : null);

        $included = $this->fetch('/fk-users/ada?include=favourite');
        self::assertSame(['type' => 'fk-items', 'id' => '2'], $this->relationship($included['data'] ?? null, 'favourite')['data'] ?? null);
        self::assertSame(['fk-items:2'], $this->includedKeys($included));
    }

    #[Test]
    #[Group('spec:crud-updating-relationships')]
    public function aReplaceSetsTheForeignKeyOnTheLinkedRowsAndClearsItOnTheRest(): void
    {
        $response = $this->jsonApi()->withData([['type' => 'fk-items', 'id' => '1'], ['type' => 'fk-items', 'id' => '7']])->patch('/api/fk-users/ada/relationships/items');
        $response->assertOk();
        self::assertSame(['1', '7'], $this->ids($this->decode($response)['data'] ?? null));

        self::assertSame(['1', '7'], $this->ids($this->fetch('/fk-users/ada/items?page[size]=10')['data'] ?? null));
        self::assertNull($this->attribute($this->fetch('/fk-items/2')['data'] ?? null, 'owner'));
        self::assertSame('ada', $this->attribute($this->fetch('/fk-items/7')['data'] ?? null, 'owner'));
    }

    #[Test]
    #[Group('spec:crud-updating-relationships')]
    public function anAddMovesARowFromAnotherParentAndARemoveClearsOnlyMembers(): void
    {
        $this->jsonApi()->withData([['type' => 'fk-items', 'id' => '6']])->post('/api/fk-users/ada/relationships/items')->assertOk();
        self::assertSame(['1', '2', '3', '4', '5', '6'], $this->ids($this->fetch('/fk-users/ada/items?page[size]=10')['data'] ?? null));
        self::assertSame([], $this->ids($this->fetch('/fk-users/bob/items')['data'] ?? null));

        $this->jsonApi()->withData([['type' => 'fk-items', 'id' => '1'], ['type' => 'fk-items', 'id' => '7']])->delete('/api/fk-users/ada/relationships/items')->assertOk();
        self::assertSame(['2', '3', '4', '5', '6'], $this->ids($this->fetch('/fk-users/ada/items?page[size]=10')['data'] ?? null));
        self::assertNull($this->attribute($this->fetch('/fk-items/7')['data'] ?? null, 'owner'));
    }

    #[Test]
    #[Group('spec:crud-updating-relationships')]
    public function aHasOneReplaceMovesTheForeignKeyToTheNewTarget(): void
    {
        $this->jsonApi()->withData(['type' => 'fk-profiles', 'id' => '2'])->patch('/api/fk-users/bob/relationships/profile')->assertOk();
        self::assertSame(['type' => 'fk-profiles', 'id' => '2'], $this->fetch('/fk-users/bob/relationships/profile')['data'] ?? null);

        $this->jsonApi()->withData(null)->patch('/api/fk-users/ada/relationships/profile')->assertOk();
        $none = $this->fetch('/fk-users/ada/relationships/profile');
        self::assertArrayHasKey('data', $none);
        self::assertNull($none['data']);
    }

    #[Test]
    #[Group('spec:crud-updating-relationships')]
    public function aParentWithNoInverseRelationWritesThroughTheForeignKey(): void
    {
        $this->jsonApi()->withData([['type' => 'fk-members', 'id' => '4'], ['type' => 'fk-members', 'id' => '3']])->patch('/api/fk-teams/2/relationships/members')->assertOk();
        self::assertSame(['3', '4'], $this->ids($this->fetch('/fk-teams/2/relationships/members')['data'] ?? null));
        self::assertSame(['1', '2'], $this->ids($this->fetch('/fk-teams/1/relationships/members')['data'] ?? null));

        $this->jsonApi()
            ->withResource('fk-teams', '2', relationships: ['members' => ['data' => [['type' => 'fk-members', 'id' => '1']]]])
            ->patch('/api/fk-teams/2')
            ->assertOk();
        self::assertSame(['1'], $this->ids($this->fetch('/fk-teams/2/relationships/members')['data'] ?? null));
        self::assertSame(['2'], $this->ids($this->fetch('/fk-teams/1/relationships/members')['data'] ?? null));
    }

    #[Test]
    #[Group('spec:crud-updating-relationships')]
    public function linkageTheCallerCantReachIsNotFoundAndChangesNothing(): void
    {
        $this->jsonApi()->withData([['type' => 'fk-items', 'id' => '1'], ['type' => 'fk-items', 'id' => '99']])->patch('/api/fk-users/ada/relationships/items')->assertStatus(404);
        self::assertSame(['1', '2', '3', '4', '5'], $this->ids($this->fetch('/fk-users/ada/items?page[size]=10')['data'] ?? null));
    }

    #[Test]
    #[Group('spec:crud-updating-relationships')]
    public function readOnlyAndFixedMembershipStillRefuseTheWrite(): void
    {
        $this->jsonApi()->withData([])->patch('/api/fk-users/ada/relationships/readOnlyItems')->assertStatus(403);

        $same = [];
        foreach (['1', '2', '3', '4', '5'] as $id) {
            $same[] = ['type' => 'fk-items', 'id' => $id];
        }
        $this->jsonApi()->withData($same)->patch('/api/fk-users/ada/relationships/lockedItems')->assertOk();
        $this->jsonApi()->withData([['type' => 'fk-items', 'id' => '1']])->patch('/api/fk-users/ada/relationships/lockedItems')->assertStatus(403);
        self::assertSame(['1', '2', '3', '4', '5'], $this->ids($this->fetch('/fk-users/ada/items?page[size]=10')['data'] ?? null));
    }

    #[Test]
    #[Group('spec:crud-updating-relationships')]
    public function movingARowFromAnotherParentNeedsUpdateOnThatParentOrTheRow(): void
    {
        // Only team 2 may be updated, so its write may not take member 1 from team 1.
        Gate::define('update', static function (?Authenticatable $user, mixed $subject = null): bool {
            if (!\is_object($subject) || (new \ReflectionClass($subject))->getShortName() !== 'FkTeam') {
                return false;
            }
            /** @var mixed $id */
            $id = $subject->id ?? null;

            return \is_scalar($id) && (string) $id === '2';
        });

        $this->jsonApi()->withData([['type' => 'fk-members', 'id' => '4'], ['type' => 'fk-members', 'id' => '1']])->patch('/api/fk-teams/2/relationships/members')->assertStatus(403);
        self::assertSame(['1', '2'], $this->ids($this->fetch('/fk-teams/1/relationships/members')['data'] ?? null));
        self::assertSame(['4'], $this->ids($this->fetch('/fk-teams/2/relationships/members')['data'] ?? null));
    }

    #[Test]
    #[Group('ext:atomic')]
    public function anAtomicBatchWritesTheForeignKeyAndRollsItBackOnFailure(): void
    {
        $failed = $this->atomic([
            ['op' => 'update', 'ref' => ['type' => 'fk-teams', 'id' => '2', 'relationship' => 'members'], 'data' => [['type' => 'fk-members', 'id' => '1']]],
            ['op' => 'update', 'ref' => ['type' => 'fk-teams', 'id' => '1', 'relationship' => 'members'], 'data' => [['type' => 'fk-members', 'id' => '99']]],
        ]);
        $failed->assertStatus(404);
        self::assertSame(['4'], $this->ids($this->fetch('/fk-teams/2/relationships/members')['data'] ?? null));

        $committed = $this->atomic([
            ['op' => 'update', 'ref' => ['type' => 'fk-teams', 'id' => '2', 'relationship' => 'members'], 'data' => [['type' => 'fk-members', 'id' => '1']]],
        ]);
        self::assertContains($committed->getStatusCode(), [200, 204], (string) $committed->getContent());
        self::assertSame(['1'], $this->ids($this->fetch('/fk-teams/2/relationships/members')['data'] ?? null));
    }

    /**
     * @param list<array<string, mixed>> $operations
     *
     * @return TestResponse<\Symfony\Component\HttpFoundation\Response>
     */
    protected function atomic(array $operations): TestResponse
    {
        return $this->call('POST', '/api/operations', [], [], [], [
            'HTTP_ACCEPT' => self::ATOMIC_MEDIA_TYPE,
            'CONTENT_TYPE' => self::ATOMIC_MEDIA_TYPE,
        ], (string) \json_encode(['atomic:operations' => $operations]));
    }

    /**
     * The decoded document `GET /api$path` answers, asserting a `200`.
     *
     * @param list<string> $profiles
     *
     * @return array<string, mixed>
     */
    protected function fetch(string $path, array $profiles = []): array
    {
        $request = $this->jsonApi();
        if ($profiles !== []) {
            $request = $request->withProfile(...$profiles);
        }

        $response = $request->get('/api' . $path);
        $response->assertOk();

        return $this->decode($response);
    }

    /**
     * @param TestResponse<\Symfony\Component\HttpFoundation\Response> $response
     *
     * @return array<string, mixed>
     */
    protected function decode(TestResponse $response): array
    {
        $document = \json_decode((string) $response->getContent(), true);
        self::assertIsArray($document);

        /** @var array<string, mixed> $document */
        return $document;
    }

    /**
     * @return array<string, mixed>
     */
    protected function relationship(mixed $resource, string $name): array
    {
        self::assertIsArray($resource);
        $relationships = $resource['relationships'] ?? null;
        self::assertIsArray($relationships);
        $relationship = $relationships[$name] ?? null;
        self::assertIsArray($relationship, $name . ' is rendered');

        /** @var array<string, mixed> $relationship */
        return $relationship;
    }

    /**
     * @return list<string>
     */
    protected function ids(mixed $data): array
    {
        self::assertIsArray($data);
        $ids = [];
        foreach ($data as $item) {
            self::assertIsArray($item);
            $id = $item['id'] ?? null;
            self::assertIsString($id);
            $ids[] = $id;
        }

        return $ids;
    }

    /**
     * @param array<string, mixed> $document
     *
     * @return list<string>
     */
    protected function includedKeys(array $document): array
    {
        $included = $document['included'] ?? [];
        self::assertIsArray($included);
        $keys = [];
        foreach ($included as $resource) {
            self::assertIsArray($resource);
            $type = $resource['type'] ?? null;
            $id = $resource['id'] ?? null;
            self::assertIsString($type);
            self::assertIsString($id);
            $keys[] = $type . ':' . $id;
        }
        \sort($keys);

        return $keys;
    }

    /**
     * @param list<string> $ids
     *
     * @return TestResponse<\Symfony\Component\HttpFoundation\Response>
     */
    private function revise(string $path, array $ids): TestResponse
    {
        return $this->jsonApi()
            ->withData(['type' => 'fk-revisable-grants', 'id' => 'g2', 'relationships' => ['lockedSecrets' => ['data' => \array_map(static fn(string $id): array => ['type' => 'fk-secrets', 'id' => $id], $ids)]]])
            ->patch('/api' . $path);
    }

    private function attribute(mixed $resource, string $name): mixed
    {
        self::assertIsArray($resource);
        $attributes = $resource['attributes'] ?? null;
        self::assertIsArray($attributes);

        return $attributes[$name] ?? null;
    }

    /**
     * @param array<string, mixed> $document
     *
     * @return array<string, string>
     */
    private function links(array $document): array
    {
        $links = $document['links'] ?? [];
        self::assertIsArray($links);

        $hrefs = [];
        foreach ($links as $name => $link) {
            $href = \is_array($link) ? ($link['href'] ?? null) : $link;
            self::assertIsString($href);
            $hrefs[(string) $name] = $href;
        }

        return $hrefs;
    }

    /**
     * @param array<string, mixed> $document
     *
     * @return array<array-key, mixed>
     */
    private function meta(array $document): array
    {
        $meta = $document['meta'] ?? [];
        self::assertIsArray($meta);

        return $meta;
    }
}
