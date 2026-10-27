<?php

declare(strict_types=1);

namespace haddowg\JsonApiLaravel\Tests\Conformance;

use haddowg\JsonApiLaravel\JsonApiServiceProvider;
use haddowg\JsonApiLaravel\Server\ServableResourceWarmer;
use haddowg\JsonApiLaravel\Tests\Fixtures\ActionLinks\RecordResource;
use Illuminate\Testing\TestResponse;
use Orchestra\Testbench\Attributes\DefineEnvironment;
use Orchestra\Testbench\TestCase as Orchestra;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\Test;
use Workbench\App\Models\User;

/**
 * Action links a resource declares (ADR 0064), against both data layers. Records 1, 2 and
 * 4 are released and 3 and 5 withdrawn. `review` links released records, `feature` links
 * released records for admins, and `archive`, an `asLink` action, links withdrawn ones.
 */
#[Group('actions')]
abstract class ActionLinksConformanceTestCase extends Orchestra
{
    private const string CONFIGURED_BASE_URI = 'https://api.example.test/api';

    private const string MEDIA_TYPE = 'application/vnd.api+json';

    private bool $configuredBase = false;

    /**
     * @return class-string
     */
    abstract protected function fixtureServiceProvider(): string;

    protected function seedRecords(): void {}

    /**
     * @param \Illuminate\Foundation\Application $app
     *
     * @return array<int, class-string>
     */
    protected function getPackageProviders($app): array
    {
        return [JsonApiServiceProvider::class, $this->fixtureServiceProvider()];
    }

    /**
     * @param \Illuminate\Foundation\Application $app
     */
    protected function defineEnvironment($app): void
    {
        /** @var \Illuminate\Contracts\Config\Repository $config */
        $config = $app['config'];
        $config->set('app.debug', false);
        $config->set('jsonapi.servers', ['default' => ['prefix' => 'api', 'middleware' => [], 'domain' => null]]);
        $config->set('jsonapi.base_uri', $this->configuredBase ? self::CONFIGURED_BASE_URI : '');
        $config->set('jsonapi.openapi.expose_in_prod', true);
    }

    /**
     * @param \Illuminate\Foundation\Application $app
     */
    protected function configuredBaseUri($app): void
    {
        $this->configuredBase = true;
        /** @var \Illuminate\Contracts\Config\Repository $config */
        $config = $app['config'];
        $config->set('jsonapi.base_uri', self::CONFIGURED_BASE_URI);
    }

    protected function setUp(): void
    {
        RecordResource::$variant = 'test';
        parent::setUp();
        $this->seedRecords();
    }

    protected function tearDown(): void
    {
        RecordResource::$variant = 'test';
        parent::tearDown();
    }

    #[Test]
    public function aDeclaredActionLinkRendersWhileItsConditionHolds(): void
    {
        $links = $this->linksOf('/api/records/1');

        self::assertSame(
            [
                'href' => 'http://localhost/api/records/1/-actions/review',
                'describedby' => '/docs.json#/paths/~1records~1%7Bid%7D~1-actions~1review/post',
            ],
            $links['review'] ?? null,
        );
    }

    #[Test]
    public function aDeclaredActionLinkIsAbsentWhenItsConditionFails(): void
    {
        self::assertArrayNotHasKey('review', $this->linksOf('/api/records/3'));
    }

    #[Test]
    public function eachCollectionMemberIsJudgedOnItsOwnState(): void
    {
        $data = self::at($this->fetch('/api/records'), 'data');
        self::assertIsArray($data);

        $linked = [];
        foreach ($data as $resource) {
            $links = self::at($resource, 'links');
            self::assertIsArray($links);
            $id = self::at($resource, 'id');
            self::assertIsString($id);
            $linked[$id] = \array_values(\array_diff(\array_keys($links), ['self']));
        }

        self::assertSame(['1' => ['review'], '2' => ['review'], '3' => ['archive'], '4' => ['review'], '5' => ['archive']], $linked);
    }

    #[Test]
    public function theActionsAbilityStillGatesTheLink(): void
    {
        $this->actingAs($this->user(admin: true));
        self::assertArrayHasKey('feature', $this->linksOf('/api/records/1'));
        // The ability passes, the condition doesn't.
        self::assertArrayNotHasKey('feature', $this->linksOf('/api/records/3'));

        $this->actingAs($this->user(admin: false));
        self::assertArrayNotHasKey('feature', $this->linksOf('/api/records/1'));
    }

    #[Test]
    public function aGuestNeverSeesTheGatedLink(): void
    {
        self::assertArrayNotHasKey('feature', $this->linksOf('/api/records/1'));
    }

    #[Test]
    public function theConditionWinsOverAsLink(): void
    {
        self::assertArrayNotHasKey('archive', $this->linksOf('/api/records/1'));
        self::assertSame('http://localhost/api/records/3/-actions/archive', self::at($this->linksOf('/api/records/3'), 'archive', 'href'));
    }

    #[Test]
    #[DefineEnvironment('configuredBaseUri')]
    public function theLinkIsOnTheConfiguredBaseUri(): void
    {
        $links = $this->linksOf('https://tenant.example/api/records/1');

        self::assertSame(self::CONFIGURED_BASE_URI . '/records/1', self::at($links, 'self', 'href'));
        self::assertSame(self::CONFIGURED_BASE_URI . '/records/1/-actions/review', self::at($links, 'review', 'href'));
    }

    #[Test]
    public function theLinkIsOnTheRequestHostAndPrefixWhenTheBaseUriIsEmpty(): void
    {
        $links = $this->linksOf('https://tenant.example/api/records/1');

        self::assertSame('https://tenant.example/api/records/1', self::at($links, 'self', 'href'));
        self::assertSame('https://tenant.example/api/records/1/-actions/review', self::at($links, 'review', 'href'));
    }

    #[Test]
    #[Group('openapi')]
    public function theDocumentDescribesEachActionLink(): void
    {
        $response = $this->get('/docs.json');
        $response->assertOk();
        $schemas = self::at($this->decode($response), 'components', 'schemas');
        self::assertSame(['$ref' => '#/components/schemas/ResourceLinks'], self::at($schemas, 'RecordsResourceLinks', 'allOf', 0));
        self::assertIsArray(self::at($schemas, 'ResourceLinks', 'properties', 'self'));
        $properties = self::at($schemas, 'RecordsResourceLinks', 'allOf', 1, 'properties');
        self::assertIsArray($properties);

        self::assertSame(['review', 'feature', 'archive'], \array_keys($properties));
        foreach ($properties as $name => $link) {
            self::assertSame('#/components/schemas/RenderedLink', self::at($link, '$ref'), (string) $name);
        }
        self::assertSame('Present while the record is released.', self::string(self::at($properties, 'review', 'description')));
        self::assertSame('The `feature` action on this resource.', self::string(self::at($properties, 'feature', 'description')));
        self::assertSame('Present while the record is withdrawn.', self::string(self::at($properties, 'archive', 'description')));
    }

    #[Test]
    public function theWarmerAcceptsTheDeclaredActionLinks(): void
    {
        self::assertSame([], $this->warm());
    }

    #[Test]
    public function anActionLinkNamingNoActionFailsTheBuild(): void
    {
        RecordResource::$variant = 'missing';

        self::assertContains(
            'The JSON:API resource ' . RecordResource::class . ' declares an action link for "retract", but no server serving type "records" has a '
            . 'resource-scope action with that path. Declare #[AsJsonApiAction(type: \'records\', path: \'retract\')], or remove the action link.',
            $this->warm(),
        );
    }

    #[Test]
    public function aDeclaredLinkNamedLikeAnActionLinkFailsTheBuild(): void
    {
        RecordResource::$variant = 'clash';

        $this->expectException(\LogicException::class);
        $this->expectExceptionMessage(\sprintf('Resource "%s" declares the link "review" and an action link for the "review" action', RecordResource::class));

        $this->warm();
    }

    #[Test]
    public function twoActionLinksForOnePathFailTheBuild(): void
    {
        RecordResource::$variant = 'duplicate';

        $this->expectException(\LogicException::class);
        $this->expectExceptionMessage(\sprintf('Resource "%s" declares the action link "review" twice.', RecordResource::class));

        $this->warm();
    }

    /**
     * @return list<string>
     */
    private function warm(): array
    {
        $warmer = $this->app?->make(ServableResourceWarmer::class);
        self::assertInstanceOf(ServableResourceWarmer::class, $warmer);

        return $warmer->warm();
    }

    private function user(bool $admin): User
    {
        return new User(['id' => 1, 'name' => 'Someone', 'can_write' => true, 'can_read' => true, 'is_admin' => $admin]);
    }

    /**
     * @return array<array-key, mixed>
     */
    private function linksOf(string $uri): array
    {
        $links = self::at($this->fetch($uri), 'data', 'links');
        self::assertIsArray($links);

        return $links;
    }

    /**
     * @return array<array-key, mixed>
     */
    private function fetch(string $uri): array
    {
        $response = $this->get($uri, ['Accept' => self::MEDIA_TYPE]);
        $response->assertOk();

        return $this->decode($response);
    }

    /**
     * @param TestResponse<\Symfony\Component\HttpFoundation\Response> $response
     *
     * @return array<array-key, mixed>
     */
    private function decode(TestResponse $response): array
    {
        $decoded = \json_decode((string) $response->getContent(), true, flags: \JSON_THROW_ON_ERROR);
        self::assertIsArray($decoded);

        return $decoded;
    }

    private static function string(mixed $value): string
    {
        self::assertIsString($value);

        return $value;
    }

    private static function at(mixed $value, int|string ...$keys): mixed
    {
        foreach ($keys as $key) {
            if (!\is_array($value) || !\array_key_exists($key, $value)) {
                return null;
            }
            $value = $value[$key];
        }

        return $value;
    }
}
