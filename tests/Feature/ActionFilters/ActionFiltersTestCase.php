<?php

declare(strict_types=1);

namespace haddowg\JsonApiLaravel\Tests\Feature\ActionFilters;

use haddowg\JsonApiLaravel\JsonApiServiceProvider;
use haddowg\JsonApiLaravel\OpenApi\DocumentFactory;
use haddowg\JsonApiLaravel\Tests\Fixtures\ActionFilters\ActionFiltersServiceProvider;
use haddowg\JsonApiLaravel\Tests\Support\InteractsWithOpenApiDocument;
use Illuminate\Testing\TestResponse;
use Orchestra\Testbench\TestCase as Orchestra;
use PHPUnit\Framework\Attributes\Test;
use Workbench\App\Models\User;

/**
 * A collection action taking its type's filters: `GET /gadgets/-actions/count` takes all of
 * them and `POST /gadgets/-actions/paint` takes `name` and `weightRange`. The filters are
 * checked as the collection fetch checks them, the type's `viewAny` gate runs beside the
 * action's own ability, and the handler reads the rows through `filtered()`.
 *
 * Visible gadgets: 1 Anvil red 50 large, 2 Bolt blue 1 small, 3 Crank red 5 small, 5 Easel
 * green 8 large. Gadget 4, Dynamo red 20 large, is archived and never visible.
 *
 * @internal
 */
abstract class ActionFiltersTestCase extends Orchestra
{
    use InteractsWithOpenApiDocument;

    public const string MEDIA_TYPE = 'application/vnd.api+json';

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
        $config->set('gadgets.store', $this->store());

        return [JsonApiServiceProvider::class, ActionFiltersServiceProvider::class];
    }

    protected function setUp(): void
    {
        parent::setUp();
        $this->actingAs(new User(['id' => 1, 'name' => 'Ada', 'can_read' => true, 'can_write' => true, 'is_admin' => false]));
    }

    #[Test]
    public function anActionTakingAllFiltersAppliesThemWithTheirDefaults(): void
    {
        // `size` defaults to large, as it does for GET /gadgets.
        self::assertSame(['1', '5'], $this->ids($this->countAction('')));
        self::assertSame(['1'], $this->ids($this->countAction('?filter[colour]=red')));
        self::assertSame(['3'], $this->ids($this->countAction('?filter[colour]=red&filter[size]=small')));
        self::assertSame(['3'], $this->ids($this->countAction('?filter[weightRange][min]=2&filter[weightRange][max]=6&filter[size]=small')));
    }

    #[Test]
    public function anActionTakingNamedFiltersAppliesOnlyThose(): void
    {
        // `size` is not taken, so its default does not apply either.
        self::assertSame(['1', '2', '3', '5'], $this->ids($this->paintAction('')));
        self::assertSame(['1', '3'], $this->ids($this->paintAction('?filter[name]=n')));
        self::assertSame(['3'], $this->ids($this->paintAction('?filter[name]=n&filter[weightRange][max]=10')));
    }

    #[Test]
    public function anUndeclaredFilterKeyIs400(): void
    {
        $this->assertFilterError($this->postAction('/api/gadgets/-actions/paint?filter[nope]=1'), 'FILTERING_UNRECOGNIZED');
        $this->assertFilterError($this->getJsonApi('/api/gadgets/-actions/count?filter[nope]=1'), 'FILTERING_UNRECOGNIZED');
    }

    #[Test]
    public function aDeclaredFilterTheActionDoesNotTakeIs400(): void
    {
        $this->assertFilterError($this->postAction('/api/gadgets/-actions/paint?filter[colour]=red'), 'FILTERING_UNRECOGNIZED');
    }

    #[Test]
    public function aBadFilterValueIs400(): void
    {
        $response = $this->postAction('/api/gadgets/-actions/paint?filter[weightRange][min]=banana');

        $this->assertFilterError($response, 'FILTER_VALUE_INVALID');
        self::assertSame('filter[weightRange]', $response->json('errors.0.source.parameter'));
    }

    #[Test]
    public function aCallerWhoCannotListTheTypeIs403(): void
    {
        $this->actingAs(new User(['id' => 2, 'name' => 'Bea', 'can_read' => false, 'can_write' => true, 'is_admin' => false]));

        $this->getJsonApi('/api/gadgets/-actions/count')->assertForbidden();
        $this->postAction('/api/gadgets/-actions/paint')->assertForbidden();
    }

    #[Test]
    public function theActionsOwnAbilityStillApplies(): void
    {
        $this->actingAs(new User(['id' => 3, 'name' => 'Cy', 'can_read' => true, 'can_write' => false, 'is_admin' => false]));

        $this->getJsonApi('/api/gadgets/-actions/count')->assertOk();
        $this->postAction('/api/gadgets/-actions/paint')->assertForbidden();
    }

    #[Test]
    public function theDocumentListsTheFiltersAnActionTakes(): void
    {
        $doc = $this->resolve(DocumentFactory::class)->forServer('default')->toArray();

        self::assertSame(
            ['filter[name]', 'filter[colour]', 'filter[weightRange]', 'filter[size]'],
            $this->filterParameters($this->arrayAt($doc, 'paths', '/gadgets/-actions/count', 'get')),
        );
        self::assertSame(
            ['filter[name]', 'filter[weightRange]'],
            $this->filterParameters($this->arrayAt($doc, 'paths', '/gadgets/-actions/paint', 'post')),
        );
    }

    /**
     * @return TestResponse<\Symfony\Component\HttpFoundation\Response>
     */
    protected function countAction(string $query): TestResponse
    {
        return $this->getJsonApi('/api/gadgets/-actions/count' . $query)->assertOk();
    }

    /**
     * @return TestResponse<\Symfony\Component\HttpFoundation\Response>
     */
    protected function paintAction(string $query): TestResponse
    {
        return $this->postAction('/api/gadgets/-actions/paint' . $query)->assertOk();
    }

    /**
     * @return TestResponse<\Symfony\Component\HttpFoundation\Response>
     */
    protected function getJsonApi(string $uri): TestResponse
    {
        return $this->get($uri, ['Accept' => self::MEDIA_TYPE]);
    }

    /**
     * @return TestResponse<\Symfony\Component\HttpFoundation\Response>
     */
    protected function postAction(string $uri): TestResponse
    {
        return $this->call('POST', $uri, [], [], [], ['HTTP_ACCEPT' => self::MEDIA_TYPE, 'CONTENT_TYPE' => self::MEDIA_TYPE]);
    }

    /**
     * @param TestResponse<\Symfony\Component\HttpFoundation\Response> $response
     *
     * @return list<string>
     */
    protected function ids(TestResponse $response): array
    {
        $ids = $response->json('meta.ids');
        self::assertIsArray($ids);
        $ids = \array_map(static fn(mixed $id): string => \is_scalar($id) ? (string) $id : '', \array_values($ids));
        \sort($ids);

        return $ids;
    }

    /**
     * @param TestResponse<\Symfony\Component\HttpFoundation\Response> $response
     */
    private function assertFilterError(TestResponse $response, string $code): void
    {
        $response->assertStatus(400);
        self::assertSame($code, $response->json('errors.0.code'));
    }

    /**
     * @param array<array-key, mixed> $operation
     *
     * @return list<string>
     */
    private function filterParameters(array $operation): array
    {
        $names = [];
        foreach ($this->arrayAt($operation, 'parameters') as $parameter) {
            if (\is_array($parameter) && \is_string($parameter['name'] ?? null) && \str_starts_with($parameter['name'], 'filter[')) {
                $names[] = $parameter['name'];
            }
        }

        return $names;
    }
}
