<?php

declare(strict_types=1);

namespace haddowg\JsonApiLaravel\Tests\Conformance;

use haddowg\JsonApi\Atomic\AtomicExtension;
use haddowg\JsonApiLaravel\JsonApiServiceProvider;
use Illuminate\Testing\TestResponse;
use Orchestra\Testbench\TestCase as Orchestra;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\Test;

/**
 * The composite-attribute conformance witness (core docs/constraints.md), run against both
 * providers — the Laravel twin of the Symfony bundle's `CompositeConformanceTestCase`:
 * the bridge cascades an {@see \haddowg\JsonApi\Resource\Field\Obj}'s children and a
 * {@see \haddowg\JsonApi\Resource\Field\OneOf}'s selected variant children through
 * dotted-key rules, surfacing per-child `422`s with `/data/attributes/<field>/<child>`
 * pointers and rejecting an unknown discriminator; a
 * {@see \haddowg\JsonApi\Resource\Constraint\Shape} is value-validated by the core opis
 * validator. Valid composite values round-trip persistence as a single value each — on
 * the Eloquent kernel, a real `json` column.
 */
abstract class CompositeConformanceTestCase extends Orchestra
{
    public const string MEDIA_TYPE = 'application/vnd.api+json';

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
        return [
            JsonApiServiceProvider::class,
            $this->conformanceServiceProvider(),
        ];
    }

    /**
     * @param \Illuminate\Foundation\Application $app
     */
    protected function defineEnvironment($app): void
    {
        /** @var \Illuminate\Contracts\Config\Repository $config */
        $config = $app['config'];
        $config->set('jsonapi.base_uri', 'http://localhost/api');
        $config->set('jsonapi.atomic_operations.enabled', true);
    }

    protected function seedConformanceData(): void {}

    protected function setUp(): void
    {
        parent::setUp();
        $this->seedConformanceData();
    }

    #[Test]
    #[Group('spec:crud')]
    public function aValidCompositeCreates(): void
    {
        $response = $this->writeJsonApi('POST', '/api/composites', [
            'data' => [
                'type' => 'composites',
                'attributes' => [
                    'name' => 'Gadget',
                    'address' => ['street' => '1 High St', 'city' => 'London', 'postcode' => 'EC1'],
                    'block' => ['kind' => 'image', 'src' => 'https://example.test/a.png', 'alt' => 'A photo'],
                    'contact' => ['kind' => 'email', 'address' => 'ada@example.test'],
                ],
            ],
        ]);

        $response->assertStatus(201);
    }

    #[Test]
    #[Group('spec:crud')]
    public function compositeValuesRoundTripThroughPersistence(): void
    {
        // Scalar children of all three composite kinds, written as one value per
        // attribute and read back equal — on the Eloquent kernel this is the
        // single-json-column round-trip (an int `level` survives json encoding).
        $attributes = [
            'name' => 'Gadget',
            'address' => ['street' => '1 High St', 'city' => 'London', 'postcode' => 'EC1'],
            'block' => ['kind' => 'heading', 'text' => 'Hello', 'level' => 2],
            'contact' => ['kind' => 'phone', 'number' => '+44 20 7946 0000'],
        ];

        $created = $this->writeJsonApi('POST', '/api/composites', [
            'data' => ['type' => 'composites', 'attributes' => $attributes],
        ]);
        $created->assertStatus(201);

        $id = $created->json('data.id');
        self::assertIsString($id);

        $fetched = $this->getJson('/api/composites/' . $id, ['Accept' => self::MEDIA_TYPE]);
        $fetched->assertStatus(200);
        self::assertSame($attributes, $fetched->json('data.attributes'));
    }

    #[Test]
    #[Group('spec:crud')]
    public function aCompositeValueReplacesOnUpdate(): void
    {
        // The seeded widget (id 1) carries an address; a PATCH sending a complete new
        // address replaces the stored value, and a fresh read serves it from persistence.
        $updated = $this->writeJsonApi('PATCH', '/api/composites/1', [
            'data' => [
                'type' => 'composites',
                'id' => '1',
                'attributes' => [
                    'address' => ['street' => '2 Low Rd', 'city' => 'Leeds', 'postcode' => 'LS1'],
                ],
            ],
        ]);
        $updated->assertStatus(200);

        $fetched = $this->getJson('/api/composites/1', ['Accept' => self::MEDIA_TYPE]);
        $fetched->assertStatus(200);
        self::assertSame(
            ['street' => '2 Low Rd', 'city' => 'Leeds', 'postcode' => 'LS1'],
            $fetched->json('data.attributes.address'),
        );
    }

    #[Test]
    #[Group('spec:crud')]
    public function anInvalidObjChildPointsAtTheChild(): void
    {
        $response = $this->writeJsonApi('POST', '/api/composites', [
            'data' => [
                'type' => 'composites',
                'attributes' => [
                    'name' => 'Gadget',
                    'address' => ['street' => '1 High St', 'city' => ''], // city blank, postcode missing
                ],
            ],
        ]);

        $pointers = $this->pointers($response);
        self::assertContains('/data/attributes/address/city', $pointers);
        self::assertContains('/data/attributes/address/postcode', $pointers);
    }

    #[Test]
    #[Group('spec:crud')]
    public function anInvalidOneOfVariantChildPointsAtTheChild(): void
    {
        $response = $this->writeJsonApi('POST', '/api/composites', [
            'data' => [
                'type' => 'composites',
                'attributes' => [
                    'name' => 'Gadget',
                    'block' => ['kind' => 'heading', 'text' => 'Hi', 'level' => 99], // level out of 1..6
                ],
            ],
        ]);

        self::assertContains('/data/attributes/block/level', $this->pointers($response));
    }

    #[Test]
    #[Group('spec:crud')]
    public function anUnknownDiscriminatorIsRejected(): void
    {
        $response = $this->writeJsonApi('POST', '/api/composites', [
            'data' => [
                'type' => 'composites',
                'attributes' => [
                    'name' => 'Gadget',
                    'block' => ['kind' => 'video', 'url' => 'https://example.test/v.mp4'],
                ],
            ],
        ]);

        self::assertContains('/data/attributes/block/kind', $this->pointers($response));
    }

    #[Test]
    #[Group('spec:crud')]
    public function aShapeConstraintValueViolationPointsUnderTheField(): void
    {
        // kind=email selects the email branch of the Shape's oneOf, but `address` is
        // missing — the core opis SchemaValueValidator rejects it, and the leaf pointer
        // is prefixed with the field pointer.
        $response = $this->writeJsonApi('POST', '/api/composites', [
            'data' => [
                'type' => 'composites',
                'attributes' => [
                    'name' => 'Gadget',
                    'contact' => ['kind' => 'email'], // missing `address`
                ],
            ],
        ]);

        $pointers = $this->pointers($response);
        self::assertNotSame([], $pointers);
        foreach ($pointers as $pointer) {
            self::assertStringStartsWith('/data/attributes/contact', $pointer);
        }
    }

    #[Test]
    #[Group('spec:crud')]
    public function aShapeConstraintUnknownDiscriminatorIsRejected(): void
    {
        // A discriminator matching neither branch fails the whole oneOf.
        $response = $this->writeJsonApi('POST', '/api/composites', [
            'data' => [
                'type' => 'composites',
                'attributes' => [
                    'name' => 'Gadget',
                    'contact' => ['kind' => 'fax', 'number' => '123'],
                ],
            ],
        ]);

        $pointers = $this->pointers($response);
        self::assertNotSame([], $pointers);
        foreach ($pointers as $pointer) {
            self::assertStringStartsWith('/data/attributes/contact', $pointer);
        }
    }

    #[Test]
    #[Group('spec:crud')]
    public function anEmptyObjectSatisfiesAnObjectShapedConstraint(): void
    {
        $created = $this->rawJsonApi('POST', '/api/composite-settings', '{"data":{"type":"composite-settings","attributes":{"name":"Gadget","settings":{}}}}');

        $created->assertStatus(201);
        self::assertStringContainsString('"settings":{}', $this->body($created));

        $id = $created->json('data.id');
        self::assertIsString($id);

        $fetched = $this->getJson('/api/composite-settings/' . $id, ['Accept' => self::MEDIA_TYPE]);
        $fetched->assertStatus(200);
        self::assertStringContainsString('"settings":{}', $this->body($fetched));
    }

    #[Test]
    #[Group('spec:crud')]
    public function anEmptyArrayFailsAnObjectShapedConstraint(): void
    {
        $response = $this->rawJsonApi('POST', '/api/composite-settings', '{"data":{"type":"composite-settings","attributes":{"name":"Gadget","settings":[]}}}');

        $this->assertOnlyPointer('/data/attributes/settings', $response);
    }

    #[Test]
    #[Group('spec:crud')]
    public function aNonEmptyMapIsUnchanged(): void
    {
        $created = $this->rawJsonApi('POST', '/api/composite-settings', '{"data":{"type":"composite-settings","attributes":{"name":"Gadget","settings":{"theme":"dark","density":2}}}}');

        $created->assertStatus(201);
        self::assertStringContainsString('"settings":{"theme":"dark","density":2}', $this->body($created));
    }

    #[Test]
    #[Group('spec:crud')]
    public function aStoredEmptyMapSurvivesAPartialUpdate(): void
    {
        // The update folds the stored attributes under the incoming partial; the stored
        // empty map must not fail the rules of a field the client did not send.
        $created = $this->rawJsonApi('POST', '/api/composite-settings', '{"data":{"type":"composite-settings","attributes":{"name":"Gadget","settings":{}}}}');
        $created->assertStatus(201);
        $id = $created->json('data.id');
        self::assertIsString($id);

        $updated = $this->rawJsonApi('PATCH', '/api/composite-settings/' . $id, '{"data":{"type":"composite-settings","id":"' . $id . '","attributes":{"name":"Renamed"}}}');

        $updated->assertStatus(200);
        self::assertStringContainsString('"settings":{}', $this->body($updated));
    }

    #[Test]
    #[Group('spec:atomic')]
    public function anAtomicResultKeepsAnEmptyMapAnObject(): void
    {
        $response = $this->rawAtomic('{"atomic:operations":['
            . '{"op":"add","data":{"type":"composite-settings","attributes":{"name":"Gadget","settings":{}}}},'
            . '{"op":"update","ref":{"type":"composite-settings","id":"1"},"data":{"type":"composite-settings","id":"1","attributes":{"settings":{}}}},'
            . '{"op":"update","ref":{"type":"composite-settings","id":"1"},"data":{"type":"composite-settings","id":"1","attributes":{"settings":{"theme":"dark"}}}}'
            . ']}');

        $response->assertStatus(200);
        $body = $this->body($response);
        self::assertSame(2, \substr_count($body, '"settings":{}'), $body);
        self::assertStringContainsString('"settings":{"theme":"dark"}', $body);
        self::assertStringNotContainsString('"settings":[]', $body);
        self::assertStringNotContainsString('"links":[]', $body);
        self::assertStringNotContainsString('"source":[]', $body);
    }

    #[Test]
    #[Group('spec:atomic')]
    public function anAtomicValidationErrorPointsAtTheOperationAttribute(): void
    {
        $response = $this->rawAtomic('{"atomic:operations":['
            . '{"op":"add","data":{"type":"composite-settings","attributes":{"name":"Gadget","settings":{}}}},'
            . '{"op":"add","data":{"type":"composite-settings","attributes":{"name":"Gadget","settings":[]}}}'
            . ']}');

        $this->assertOnlyPointer('/atomic:operations/1/data/attributes/settings', $response);
        $this->assertNoEmptyArrayMembers($response);
    }

    #[Test]
    #[Group('spec:atomic')]
    public function aReturnedAtomicErrorRendersNoEmptyMembers(): void
    {
        // A missing update target is RETURNED as an error response (not thrown), which the
        // backend re-raises; the rebuilt error document carries no empty `source`/`links`.
        $response = $this->rawAtomic('{"atomic:operations":['
            . '{"op":"add","data":{"type":"composite-settings","attributes":{"name":"Gadget","settings":{}}}},'
            . '{"op":"remove","ref":{"type":"composite-settings","id":"999"}}'
            . ']}');

        $response->assertStatus(404);
        $this->assertNoEmptyArrayMembers($response);
        self::assertSame('/atomic:operations/1', $response->json('errors.0.source.pointer'));
    }

    /**
     * Every error points at `$pointer`. Core's opis value validator appends the root
     * instance pointer `/` to a whole-value violation, so a trailing slash is tolerated.
     *
     * @param TestResponse<\Symfony\Component\HttpFoundation\Response> $response
     */
    private function assertOnlyPointer(string $pointer, TestResponse $response): void
    {
        $pointers = $this->pointers($response);
        self::assertNotSame([], $pointers);
        foreach ($pointers as $actual) {
            self::assertSame($pointer, \rtrim($actual, '/'));
        }
    }

    /**
     * @param TestResponse<\Symfony\Component\HttpFoundation\Response> $response
     */
    private function assertNoEmptyArrayMembers(TestResponse $response): void
    {
        $body = $this->body($response);
        self::assertStringNotContainsString('"source":[]', $body);
        self::assertStringNotContainsString('"links":[]', $body);
        self::assertStringNotContainsString('"meta":[]', $body);
        self::assertStringNotContainsString('"jsonapi":[]', $body);
    }

    /**
     * @param TestResponse<\Symfony\Component\HttpFoundation\Response> $response
     */
    private function body(TestResponse $response): string
    {
        return (string) $response->baseResponse->getContent();
    }

    /**
     * Sends a hand-written JSON body, so a `{}` reaches the server as an object rather than
     * whatever a PHP array would encode to.
     *
     * @return TestResponse<\Symfony\Component\HttpFoundation\Response>
     */
    private function rawJsonApi(string $method, string $uri, string $json): TestResponse
    {
        return $this->call($method, $uri, [], [], [], [
            'HTTP_ACCEPT' => self::MEDIA_TYPE,
            'CONTENT_TYPE' => self::MEDIA_TYPE,
        ], $json);
    }

    /**
     * @return TestResponse<\Symfony\Component\HttpFoundation\Response>
     */
    private function rawAtomic(string $json): TestResponse
    {
        $mediaType = self::MEDIA_TYPE . '; ext="' . AtomicExtension::URI . '"';

        return $this->call('POST', '/api/operations', [], [], [], [
            'HTTP_ACCEPT' => $mediaType,
            'CONTENT_TYPE' => $mediaType,
        ], $json);
    }

    /**
     * @param TestResponse<\Symfony\Component\HttpFoundation\Response> $response
     *
     * @return list<string>
     */
    private function pointers(TestResponse $response): array
    {
        $response->assertStatus(422);

        /** @var list<array<string, mixed>> $errors */
        $errors = $response->json('errors');
        self::assertIsArray($errors);

        $pointers = [];
        foreach ($errors as $error) {
            self::assertSame('422', $error['status'] ?? null);
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
    protected function writeJsonApi(string $method, string $uri, array $document): TestResponse
    {
        return $this->json($method, $uri, $document, [
            'Accept' => self::MEDIA_TYPE,
            'CONTENT_TYPE' => self::MEDIA_TYPE,
        ]);
    }
}
