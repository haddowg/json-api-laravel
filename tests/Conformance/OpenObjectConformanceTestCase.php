<?php

declare(strict_types=1);

namespace haddowg\JsonApiLaravel\Tests\Conformance;

use haddowg\JsonApi\OpenApi\GeneratorContract;
use haddowg\JsonApiLaravel\JsonApiServiceProvider;
use haddowg\JsonApiLaravel\OpenApi\DocumentFactory;
use haddowg\JsonApiLaravel\Tests\Support\InteractsWithOpenApiDocument;
use Illuminate\Testing\TestResponse;
use Orchestra\Testbench\TestCase as Orchestra;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\Test;
use Workbench\App\Validation\Sheet;

/**
 * The open-object conformance witness, run against both providers, the Laravel twin of
 * the Symfony bundle's `OpenObjectConformanceTestCase`: an `Obj` declaring
 * `additionalProperties()` round-trips the members it does not declare, and the
 * validator checks each one against the member field at its own pointer, its key
 * escaped, and each object's member count at the object.
 */
abstract class OpenObjectConformanceTestCase extends Orchestra
{
    use InteractsWithOpenApiDocument;

    public const string MEDIA_TYPE = 'application/vnd.api+json';

    private const array ROWS = [
        ['Key' => 'RFP-123', 'Vendor' => 'Acme Media', 'CPL' => '42'],
        ['Key' => 'RFP-124', 'Unit/Price' => '3', 'Cost [GBP]' => '9', 'Unit.Cost' => '1'],
    ];

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
        $config->set('jsonapi.base_uri', 'http://localhost/api');
    }

    protected function seedConformanceData(): void {}

    protected function setUp(): void
    {
        parent::setUp();
        $this->seedConformanceData();
    }

    #[Test]
    #[Group('spec:crud')]
    public function rowsKeyedByArbitraryHeadersRoundTrip(): void
    {
        $created = $this->create(['rows' => self::ROWS, 'labels' => ['a/b' => 'x', 'c' => null]]);
        $created->assertStatus(201);
        self::assertSame(self::ROWS, $created->json('data.attributes.rows'));

        $id = $created->json('data.id');
        self::assertIsString($id);
        $fetched = $this->getJson('/api/sheets/' . $id, ['Accept' => self::MEDIA_TYPE]);
        $fetched->assertStatus(200);
        self::assertSame(self::ROWS, $fetched->json('data.attributes.rows'));
        self::assertSame(['a/b' => 'x', 'c' => null], $fetched->json('data.attributes.labels'));
    }

    #[Test]
    #[Group('spec:crud')]
    public function aPartialUpdateMergesTheMembers(): void
    {
        $this->update(['labels' => ['size' => 'small', 'mood' => 'calm'], 'settings' => ['limits' => ['weekly' => 50]]])->assertStatus(200);

        $fetched = $this->getJson('/api/sheets/1', ['Accept' => self::MEDIA_TYPE]);
        self::assertSame(['tone' => 'warm', 'size' => 'small', 'mood' => 'calm'], $fetched->json('data.attributes.labels'));
        self::assertSame(['theme' => 'dark', 'limits' => ['daily' => 10, 'weekly' => 50]], $fetched->json('data.attributes.settings'));
    }

    #[Test]
    #[Group('spec:crud')]
    public function anInvalidMemberPointsAtItsEscapedKey(): void
    {
        $long = \str_repeat('x', 21);
        $response = $this->create(['rows' => [
            ['Key' => 'RFP-1', 'Vendor' => $long],
            ['Key' => 'RFP-2', 'Unit/Price' => $long, 'a~b' => $long, 'Unit.Cost' => $long],
        ]]);

        self::assertSame(
            ['/data/attributes/rows/0/Vendor', '/data/attributes/rows/1/Unit~1Price', '/data/attributes/rows/1/a~0b', '/data/attributes/rows/1/Unit.Cost'],
            $this->pointers($response),
        );
        self::assertSame(\array_fill(0, 4, 'VALIDATION_FAILED'), \array_column($this->errors($response), 'code'));
    }

    #[Test]
    #[Group('spec:crud')]
    public function aNullMemberIsRefusedUnlessTheMemberFieldIsNullable(): void
    {
        $response = $this->create(['rows' => [['Key' => 'RFP-1', 'Vendor' => null]], 'labels' => ['a' => null]]);

        // A null fails the not-null guard and the string rule, as it does on an attribute.
        self::assertSame(['/data/attributes/rows/0/Vendor'], \array_values(\array_unique($this->pointers($response))));
    }

    #[Test]
    #[Group('spec:crud')]
    public function memberCountsAreEnforcedAtTheObject(): void
    {
        $response = $this->create([
            'rows' => [['Key' => 'RFP-1', 'a' => '1', 'b' => '2', 'c' => '3', 'd' => '4']],
            'labels' => [],
        ]);

        self::assertSame(['/data/attributes/rows/0', '/data/attributes/labels'], $this->pointers($response));
    }

    #[Test]
    #[Group('spec:crud')]
    public function aDeclaredChildKeepsItsOwnRules(): void
    {
        $response = $this->update(['rows' => [['Vendor' => 'Acme']]]);

        self::assertSame(['/data/attributes/rows/0/Key'], $this->pointers($response));
    }

    #[Test]
    #[Group('spec:crud')]
    public function anOpenObjectNestedInAClosedOneIsValidated(): void
    {
        $response = $this->update(['settings' => ['theme' => 'light', 'limits' => ['daily' => -1]]]);

        self::assertSame(['/data/attributes/settings/limits/daily'], $this->pointers($response));
    }

    #[Test]
    #[Group('spec:crud')]
    public function aClosedObjectStillRefusesAnUndeclaredMember(): void
    {
        $response = $this->update(['settings' => ['theme' => 'light', 'colour' => 'red']]);

        $response->assertStatus(400);
        self::assertSame('ATTRIBUTE_MEMBER_UNRECOGNIZED', $response->json('errors.0.code'));
        self::assertSame('/data/attributes/settings/colour', $response->json('errors.0.source.pointer'));
    }

    #[Test]
    #[Group('spec:crud')]
    public function readOnlyMembersAreIgnoredOnAWrite(): void
    {
        $this->update(['metrics' => ['views' => 'many', 'clicks' => 9]])->assertStatus(200);

        $fetched = $this->getJson('/api/sheets/1', ['Accept' => self::MEDIA_TYPE]);
        self::assertSame(Sheet::SEED_METRICS, $fetched->json('data.attributes.metrics'));
    }

    #[Test]
    #[Group('openapi')]
    public function theOpenApiDocumentCarriesTheMemberSchemas(): void
    {
        $document = $this->resolve(DocumentFactory::class)->forServer('default')->toArray();

        self::assertSame(['contract' => \max(GeneratorContract::BASELINE, GeneratorContract::OPEN_OBJECTS)], $this->arrayAt($document, 'info', 'x-generator'));
        $attributes = $this->arrayAt($document, 'components', 'schemas', 'SheetsCreateAttributes', 'properties');
        self::assertSame([
            'type' => 'object',
            'maxProperties' => 4,
            'properties' => ['Key' => ['type' => 'string', 'minLength' => 1]],
            'required' => ['Key'],
            'additionalProperties' => ['type' => 'string', 'maxLength' => 20],
        ], $this->arrayAt($attributes, 'rows', 'items'));
        self::assertSame(['type' => ['object', 'null'], 'additionalProperties' => false], $this->arrayAt($attributes, 'metrics'));
    }

    /**
     * @param array<string, mixed> $attributes
     *
     * @return TestResponse<\Symfony\Component\HttpFoundation\Response>
     */
    private function create(array $attributes): TestResponse
    {
        return $this->writeJsonApi('POST', '/api/sheets', [
            'data' => ['type' => 'sheets', 'attributes' => ['name' => 'Sheet', ...$attributes]],
        ]);
    }

    /**
     * @param array<string, mixed> $attributes
     *
     * @return TestResponse<\Symfony\Component\HttpFoundation\Response>
     */
    private function update(array $attributes): TestResponse
    {
        return $this->writeJsonApi('PATCH', '/api/sheets/1', [
            'data' => ['type' => 'sheets', 'id' => '1', 'attributes' => $attributes],
        ]);
    }

    /**
     * @param TestResponse<\Symfony\Component\HttpFoundation\Response> $response
     *
     * @return list<array<string, mixed>>
     */
    private function errors(TestResponse $response): array
    {
        $response->assertStatus(422);

        /** @var list<array<string, mixed>> $errors */
        $errors = $response->json('errors');
        self::assertIsArray($errors);

        return $errors;
    }

    /**
     * @param TestResponse<\Symfony\Component\HttpFoundation\Response> $response
     *
     * @return list<string>
     */
    private function pointers(TestResponse $response): array
    {
        $pointers = [];
        foreach ($this->errors($response) as $error) {
            /** @var array<string, mixed> $source */
            $source = $error['source'] ?? [];
            $pointer = $source['pointer'] ?? null;
            self::assertIsString($pointer);
            $pointers[] = $pointer;
        }

        return $pointers;
    }

    /**
     * @param array<string, mixed> $document
     *
     * @return TestResponse<\Symfony\Component\HttpFoundation\Response>
     */
    private function writeJsonApi(string $method, string $uri, array $document): TestResponse
    {
        return $this->json($method, $uri, $document, [
            'Accept' => self::MEDIA_TYPE,
            'CONTENT_TYPE' => self::MEDIA_TYPE,
        ]);
    }
}
