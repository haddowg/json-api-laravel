<?php

declare(strict_types=1);

namespace haddowg\JsonApiLaravel\Tests\Feature\ThroughAccess;

use haddowg\JsonApi\Resource\AbstractResource;
use haddowg\JsonApi\Resource\Field\RelationInterface;
use haddowg\JsonApi\Resource\Filter\RelationHop;
use haddowg\JsonApiLaravel\Authorization\OperationSecurityFacts;
use haddowg\JsonApiLaravel\JsonApiServiceProvider;
use haddowg\JsonApiLaravel\Tests\Fixtures\ThroughAccess\CrateResource;
use haddowg\JsonApiLaravel\Tests\Fixtures\ThroughAccess\ParcelResource;
use haddowg\JsonApiLaravel\Tests\Fixtures\ThroughAccess\ThroughAccessServiceProvider;
use Illuminate\Testing\TestResponse;
use Orchestra\Testbench\TestCase as Orchestra;
use PHPUnit\Framework\Attributes\Test;
use Workbench\App\Models\User;

/**
 * A filter that reaches through a relation matches only rows the caller may read, on both
 * stores. Parcels are scoped to their owner, secret tags to admins, vaults list and safes read
 * only for admins, and the `ledger` and `auditor` relations carry their own read permissions.
 * User 1 is ada, user 2 grace, user 3 an admin.
 *
 * @internal
 */
abstract class ThroughAccessTestCase extends Orchestra
{
    private const string MEDIA_TYPE = 'application/vnd.api+json';

    private const string QUERIES = 'application/vnd.api+json; profile="https://haddowg.github.io/json-api/profiles/relationship-queries/"';

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
        $config->set('through.store', $this->store());

        return [JsonApiServiceProvider::class, ThroughAccessServiceProvider::class];
    }

    #[Test]
    public function aRowTheScopeHidesNeverMatchesThroughAHop(): void
    {
        // Parcel 2 is grace's, so ada can't find crate 1 through its label.
        self::assertSame([], $this->crateIds('ada', 'filter[parcelLabel]=banana'));
        self::assertSame(['1'], $this->crateIds('grace', 'filter[parcelLabel]=banana'));
        self::assertSame(['1'], $this->crateIds('admin', 'filter[parcelLabel]=banana'));
    }

    #[Test]
    public function aCallerTheScopeDoesNotAffectSeesTheSameRows(): void
    {
        self::assertSame(['1'], $this->crateIds('ada', 'filter[parcelLabel]=apple'));
        self::assertSame(['2'], $this->crateIds('ada', 'filter[parcelLabel]=cherry'));
        self::assertSame(['1'], $this->crateIds('ada', 'filter[name]=alpha'));
        self::assertSame(['1', '2', '3'], $this->crateIds('ada', ''));
        self::assertSame(['1'], $this->crateIds('admin', 'filter[vaultCode]=v1'));
        self::assertSame(['1'], $this->crateIds('admin', 'filter[safeCode]=s1'));
        self::assertSame(['1'], $this->crateIds('admin', 'filter[ledgerCode]=l1'));
    }

    #[Test]
    public function aListMatchesOnlyThroughRowsTheCallerMayRead(): void
    {
        self::assertSame([], $this->crateIds('ada', 'filter[parcelLabels]=banana'));
        self::assertSame(['1', '2'], $this->crateIds('ada', 'filter[parcelLabels]=banana,apple,cherry'));
        self::assertSame(['1'], $this->crateIds('grace', 'filter[parcelLabels]=banana,apple,cherry'));
    }

    #[Test]
    public function aPathEndingOnAHiddenRowDoesNotMatchItsId(): void
    {
        self::assertSame([], $this->crateIds('ada', 'filter[parcel]=2'));
        self::assertSame(['1'], $this->crateIds('grace', 'filter[parcel]=2'));
    }

    #[Test]
    public function everyHopOfALongerPathIsScoped(): void
    {
        self::assertSame([], $this->crateIds('ada', 'filter[tagName]=hidden'));
        self::assertSame([], $this->crateIds('grace', 'filter[tagName]=hidden'));
        self::assertSame(['1', '2'], $this->crateIds('admin', 'filter[tagName]=hidden'));
        self::assertSame(['1'], $this->crateIds('ada', 'filter[tagName]=red'));
        self::assertSame([], $this->crateIds('grace', 'filter[tagName]=red'));
    }

    #[Test]
    public function aRelationshipExistenceFilterCountsOnlyRowsTheCallerMayRead(): void
    {
        self::assertSame(['1', '2'], $this->crateIds('ada', 'filter[hasParcels]=1'));
        self::assertSame(['1'], $this->crateIds('grace', 'filter[hasParcels]=1'));
    }

    #[Test]
    public function aFilterInsideAGroupIsScoped(): void
    {
        self::assertSame([], $this->crateIds('ada', 'filter[search]=banana'));
        self::assertSame(['1'], $this->crateIds('grace', 'filter[search]=banana'));
        self::assertSame(['2'], $this->crateIds('ada', 'filter[search]=beta'));
    }

    #[Test]
    public function aFilterThroughATypeTheCallerMayNotListIsForbidden(): void
    {
        $this->assertForbidden('ada', '/api/crates?filter[vaultCode]=v1');
    }

    #[Test]
    public function aFilterThroughATypeTheCallerMayNotReadIsForbidden(): void
    {
        $this->assertForbidden('ada', '/api/crates?filter[safeCode]=s1');
    }

    #[Test]
    public function aFilterThroughARelationTheCallerMayNotReadIsForbidden(): void
    {
        $this->assertForbidden('ada', '/api/crates?filter[ledgerCode]=l1');
    }

    #[Test]
    public function aRelationPermissionThatReadsTheParentForbidsTheFilterForEveryone(): void
    {
        $this->assertForbidden('ada', '/api/crates?filter[auditorCode]=l1');
        $this->assertForbidden('admin', '/api/crates?filter[auditorCode]=l1');
    }

    #[Test]
    public function aForbiddenHopInsideAGroupForbidsTheGroup(): void
    {
        $this->assertForbidden('ada', '/api/crates?filter[anyCode]=v1');
        self::assertSame(['1'], $this->crateIds('admin', 'filter[anyCode]=v1'));
    }

    #[Test]
    public function theSegmentNamesItsOwnRelationWhenAnotherSharesItsStorage(): void
    {
        // `sealedParcels` is admin-only and stored as `parcels`, but `parcels.label` names `parcels`.
        self::assertSame(['1'], $this->crateIds('ada', 'filter[parcelLabel]=apple'));
    }

    #[Test]
    public function aRelatedReadFiltersThroughRowsTheCallerMayRead(): void
    {
        self::assertSame([], $this->ids($this->document('grace', '/api/crates/1/parcels?filter[tagName]=hidden')->json('data')));
        self::assertSame(['2'], $this->ids($this->document('admin', '/api/crates/1/parcels?filter[tagName]=hidden')->json('data')));
        $this->assertForbidden('ada', '/api/crates/1/parcels?filter[crateVault]=v1');
    }

    #[Test]
    public function aRelationshipQueryFiltersThroughRowsTheCallerMayRead(): void
    {
        $ada = $this->document('ada', '/api/crates/1?include=parcels&relatedQuery[parcels][filter][tagName]=red', self::QUERIES);
        self::assertSame(['1'], $this->ids($ada->json('data.relationships.parcels.data')));

        $grace = $this->document('grace', '/api/crates/1?include=parcels&relatedQuery[parcels][filter][tagName]=hidden', self::QUERIES);
        self::assertSame([], $this->ids($grace->json('data.relationships.parcels.data')));

        $admin = $this->document('admin', '/api/crates/1?include=parcels&relatedQuery[parcels][filter][tagName]=hidden', self::QUERIES);
        self::assertSame(['2'], $this->ids($admin->json('data.relationships.parcels.data')));

        $this->assertForbidden('ada', '/api/crates/1?include=parcels&relatedQuery[parcels][filter][crateVault]=v1', self::QUERIES);
    }

    #[Test]
    public function anActionFilterMatchesThroughRowsTheCallerMayRead(): void
    {
        self::assertSame([], $this->tally('ada', 'filter[parcelLabel]=banana'));
        self::assertSame(['1'], $this->tally('grace', 'filter[parcelLabel]=banana'));
        self::assertSame(['1'], $this->tally('admin', 'filter[vaultCode]=v1'));
        $this->assertForbidden('ada', '/api/crates/-actions/tally?filter[vaultCode]=v1', self::MEDIA_TYPE, 'POST');
    }

    #[Test]
    public function theDocumentOffersTheRefusalOnlyWhereAHopCanRefuse(): void
    {
        $facts = $this->app?->make(OperationSecurityFacts::class);
        self::assertInstanceOf(OperationSecurityFacts::class, $facts);
        $hop = static function (AbstractResource $resource, string $name): RelationHop {
            foreach ($resource->allFields() as $field) {
                if ($field instanceof RelationInterface && $field->name() === $name) {
                    return new RelationHop($field, $resource::$type, $name);
                }
            }

            throw new \LogicException($name);
        };

        self::assertTrue($facts->canRefuseThrough('default', $hop(new CrateResource(), 'vault')));
        self::assertTrue($facts->canRefuseThrough('default', $hop(new CrateResource(), 'safe')));
        self::assertTrue($facts->canRefuseThrough('default', $hop(new CrateResource(), 'ledger')));
        self::assertFalse($facts->canRefuseThrough('default', $hop(new ParcelResource(), 'tags')));
    }

    private function assertForbidden(string $user, string $uri, string $accept = self::MEDIA_TYPE, string $method = 'GET'): void
    {
        $response = $this->send($user, $method, $uri, $accept);
        $response->assertForbidden();
        $response->assertJsonPath('errors.0.code', 'ACCESS_DENIED');
    }

    /**
     * @return list<string>
     */
    private function crateIds(string $user, string $query): array
    {
        return $this->ids($this->document($user, '/api/crates' . ($query === '' ? '' : '?' . $query))->json('data'));
    }

    /**
     * @return list<string>
     */
    private function tally(string $user, string $query): array
    {
        $response = $this->send($user, 'POST', '/api/crates/-actions/tally?' . $query)->assertOk();
        $ids = $response->json('meta.ids');
        self::assertIsArray($ids);

        return $this->ids(\array_map(static fn(mixed $id): array => ['id' => \is_scalar($id) ? (string) $id : ''], $ids));
    }

    /**
     * @return TestResponse<\Symfony\Component\HttpFoundation\Response>
     */
    private function document(string $user, string $uri, string $accept = self::MEDIA_TYPE): TestResponse
    {
        return $this->send($user, 'GET', $uri, $accept)->assertOk();
    }

    /**
     * @return TestResponse<\Symfony\Component\HttpFoundation\Response>
     */
    private function send(string $user, string $method, string $uri, string $accept = self::MEDIA_TYPE): TestResponse
    {
        $id = ['ada' => 1, 'grace' => 2, 'admin' => 3][$user];
        $this->actingAs(new User(['id' => $id, 'name' => $user, 'is_admin' => $user === 'admin']));

        return $this->call($method, $uri, [], [], [], ['HTTP_ACCEPT' => $accept, 'CONTENT_TYPE' => self::MEDIA_TYPE]);
    }

    /**
     * The ids of a list of resources or identifiers, sorted.
     *
     * @return list<string>
     */
    private function ids(mixed $resources): array
    {
        self::assertIsArray($resources);
        $ids = \array_map(static fn(mixed $resource): string => \is_array($resource) && \is_string($resource['id'] ?? null) ? $resource['id'] : '', $resources);
        \sort($ids);

        return $ids;
    }
}
