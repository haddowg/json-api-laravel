<?php

declare(strict_types=1);

namespace haddowg\JsonApiLaravel\Tests\Conformance;

use haddowg\JsonApiLaravel\JsonApiServiceProvider;
use Illuminate\Testing\TestResponse;
use Orchestra\Testbench\TestCase as Orchestra;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\Test;

/**
 * The composite-attribute conformance witness (core ADRs 0118–0121), run against both
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
