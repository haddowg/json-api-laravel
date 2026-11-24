<?php

declare(strict_types=1);

namespace haddowg\JsonApiLaravel\Tests\Feature\Scopes;

use haddowg\JsonApi\Catalog\AdditionalGate;
use haddowg\JsonApi\Catalog\OperationCatalog;
use haddowg\JsonApi\Catalog\OperationDefinition;
use haddowg\JsonApi\Catalog\SecurityEvaluatorInterface;
use haddowg\JsonApi\Catalog\SecurityLevel;
use haddowg\JsonApi\Catalog\SecurityVerdict;
use haddowg\JsonApi\OpenApi\Metadata\OperationKind;
use haddowg\JsonApiLaravel\JsonApiServiceProvider;
use haddowg\JsonApiLaravel\Tests\Fixtures\CatalogGate\CatalogGateServiceProvider;
use Orchestra\Testbench\TestCase as Orchestra;
use PHPUnit\Framework\Attributes\CoversNothing;
use PHPUnit\Framework\Attributes\TestWith;
use Workbench\App\Models\User;

/**
 * The evaluator judges a related or relationship read by every gate its endpoint applies:
 * the relation's read permission, else the parent's read gate and, for a to-many, the
 * collection gate of each type it relates to. Each verdict is checked against the answer
 * the endpoint gives the same user.
 *
 * @internal
 */
#[CoversNothing]
final class CatalogRelatedReadGateTest extends Orchestra
{
    /**
     * @param \Illuminate\Foundation\Application $app
     *
     * @return array<int, class-string>
     */
    protected function getPackageProviders($app): array
    {
        return [JsonApiServiceProvider::class, CatalogGateServiceProvider::class];
    }

    /**
     * @param \Illuminate\Foundation\Application $app
     */
    protected function defineEnvironment($app): void
    {
        /** @var \Illuminate\Contracts\Config\Repository $config */
        $config = $app['config'];
        $config->set('jsonapi.discovery.paths', []);
    }

    #[TestWith(['fetchRelated.hubs.spokes', '/api/hubs/1/spokes', false, SecurityVerdict::Denied, 403], 'fetchRelated of a to-many a user may not list')]
    #[TestWith(['fetchRelated.hubs.spokes', '/api/hubs/1/spokes', true, SecurityVerdict::Allowed, 200], 'fetchRelated of a to-many an admin may list')]
    #[TestWith(['fetchRelated.hubs.rims', '/api/hubs/1/rims', false, SecurityVerdict::Allowed, 200], 'fetchRelated of a to-many over an ungated type')]
    #[TestWith(['fetchRelated.hubs.parts', '/api/hubs/1/parts', false, SecurityVerdict::Denied, 403], 'fetchRelated of a polymorphic to-many with one refusing type')]
    #[TestWith(['fetchRelated.hubs.parts', '/api/hubs/1/parts', true, SecurityVerdict::Allowed, 200], 'fetchRelated of a polymorphic to-many every type admits')]
    #[TestWith(['fetchRelated.hubs.sharedSpokes', '/api/hubs/1/sharedSpokes', false, SecurityVerdict::Allowed, 200], 'fetchRelated of a to-many with its own read permission')]
    #[TestWith(['fetchRelated.hubs.leadSpoke', '/api/hubs/1/leadSpoke', false, SecurityVerdict::Allowed, 200], 'fetchRelated of a to-one')]
    #[TestWith(['fetchRelationship.hubs.spokes', '/api/hubs/1/relationships/spokes', false, SecurityVerdict::Denied, 403], 'fetchRelationship of a to-many a user may not list')]
    #[TestWith(['fetchRelationship.hubs.spokes', '/api/hubs/1/relationships/spokes', true, SecurityVerdict::Allowed, 200], 'fetchRelationship of a to-many an admin may list')]
    #[TestWith(['fetchRelationship.hubs.rims', '/api/hubs/1/relationships/rims', false, SecurityVerdict::Allowed, 200], 'fetchRelationship of a to-many over an ungated type')]
    #[TestWith(['fetchRelationship.hubs.parts', '/api/hubs/1/relationships/parts', false, SecurityVerdict::Denied, 403], 'fetchRelationship of a polymorphic to-many with one refusing type')]
    #[TestWith(['fetchRelationship.hubs.parts', '/api/hubs/1/relationships/parts', true, SecurityVerdict::Allowed, 200], 'fetchRelationship of a polymorphic to-many every type admits')]
    #[TestWith(['fetchRelationship.hubs.sharedSpokes', '/api/hubs/1/relationships/sharedSpokes', false, SecurityVerdict::Allowed, 200], 'fetchRelationship of a to-many with its own read permission')]
    #[TestWith(['fetchRelationship.hubs.leadSpoke', '/api/hubs/1/relationships/leadSpoke', false, SecurityVerdict::Allowed, 200], 'fetchRelationship of a to-one')]
    public function test_the_verdict_matches_what_the_endpoint_answers(string $operation, string $path, bool $admin, SecurityVerdict $verdict, int $status): void
    {
        $user = $this->user($admin);

        self::assertSame($verdict, $this->evaluator()->evaluate($this->operation($operation), $user));

        $this->actingAs($user);
        $response = $this->call('GET', $path, [], [], [], ['HTTP_ACCEPT' => 'application/vnd.api+json']);
        self::assertSame($status, $response->getStatusCode(), $path . ': ' . $response->getContent());
    }

    public function test_a_to_many_lists_each_related_collection_gate_beside_the_parent_ability(): void
    {
        $spokes = $this->operation('fetchRelated.hubs.spokes');
        self::assertSame('view', $spokes->security);
        self::assertSame(SecurityLevel::Entity, $spokes->securityLevel);
        self::assertEquals([new AdditionalGate('spokes', OperationKind::FetchCollection, 'viewAny', SecurityLevel::Type)], $spokes->additionalGates);

        self::assertEquals([new AdditionalGate('spokes', OperationKind::FetchCollection, 'viewAny', SecurityLevel::Type)], $this->operation('fetchRelationship.hubs.parts')->additionalGates);
        self::assertSame([], $this->operation('fetchRelated.hubs.rims')->additionalGates);
        self::assertSame('readShared', $this->operation('fetchRelationship.hubs.sharedSpokes')->security);
        self::assertSame([], $this->operation('fetchRelationship.hubs.sharedSpokes')->additionalGates);
        self::assertSame([], $this->operation('fetchRelated.hubs.leadSpoke')->additionalGates);
    }

    public function test_a_refusing_gate_denies_even_when_the_parent_ability_needs_the_hub(): void
    {
        $spokes = $this->operation('fetchRelated.ownedHubs.spokes');
        self::assertSame(SecurityVerdict::Denied, $this->evaluator()->evaluate($spokes, $this->user(false)));
        self::assertSame(SecurityVerdict::Depends, $this->evaluator()->evaluate($spokes, $this->user(true)));

        $this->actingAs($this->user(false));
        $response = $this->call('GET', '/api/ownedHubs/1/spokes', [], [], [], ['HTTP_ACCEPT' => 'application/vnd.api+json']);
        self::assertSame(403, $response->getStatusCode());
    }

    public function test_an_included_to_many_a_user_may_not_list_is_still_withheld_whole(): void
    {
        $this->actingAs($this->user(false));
        $response = $this->call('GET', '/api/hubs/1?include=spokes,rims,leadSpoke', [], [], [], ['HTTP_ACCEPT' => 'application/vnd.api+json']);
        self::assertSame(200, $response->getStatusCode(), (string) $response->getContent());

        /** @var array{data: array{relationships: array<string, array<string, mixed>>}, included: list<array{type: string, id: string}>} $document */
        $document = \json_decode((string) $response->getContent(), true, flags: \JSON_THROW_ON_ERROR);
        $relationships = $document['data']['relationships'];
        self::assertArrayNotHasKey('data', $relationships['spokes']);
        self::assertSame([['type' => 'rims', 'id' => '1']], $relationships['rims']['data'] ?? null);
        $included = \array_map(static fn(array $resource): string => $resource['type'] . '/' . $resource['id'], $document['included']);
        \sort($included);
        self::assertSame(['rims/1', 'spokes/1'], $included);
    }

    private function user(bool $admin): User
    {
        return new User(['id' => $admin ? 3 : 1, 'name' => $admin ? 'admin' : 'ada', 'is_admin' => $admin]);
    }

    private function evaluator(): SecurityEvaluatorInterface
    {
        $evaluator = $this->app?->make(SecurityEvaluatorInterface::class);
        self::assertInstanceOf(SecurityEvaluatorInterface::class, $evaluator);

        return $evaluator;
    }

    private function operation(string $operationId): OperationDefinition
    {
        $catalog = $this->app?->make(OperationCatalog::class);
        self::assertInstanceOf(OperationCatalog::class, $catalog);
        foreach ($catalog->forServer('default') as $operation) {
            if ($operation->operationId === $operationId) {
                return $operation;
            }
        }

        self::fail(\sprintf('No operation %s.', $operationId));
    }
}
