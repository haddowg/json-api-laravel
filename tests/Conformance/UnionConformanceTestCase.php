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

/**
 * The union witness, run against both providers, the Laravel twin of the Symfony bundle's
 * union cases. A written value's JSON type picks one member, whose rules apply at the
 * union's pointer, or at the element's inside a list member. A value no member takes is
 * one `422` naming every type the union takes. A required union refuses an empty string
 * or list, and takes `false` and `0`.
 */
abstract class UnionConformanceTestCase extends Orchestra
{
    use InteractsWithOpenApiDocument;

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

    /**
     * @return iterable<string, array{array<string, mixed>, array<string, mixed>}>
     */
    public static function roundTrips(): iterable
    {
        yield 'string member' => [['value' => 'gold'], ['value' => 'gold']];
        yield 'number member' => [['value' => 2.5], ['value' => 2.5]];
        yield 'whole number to a decimal member' => [['value' => 3], ['value' => 3]];
        yield 'boolean member' => [['value' => false], ['value' => false]];
        yield 'list member' => [['value' => ['gold', 2.5, true]], ['value' => ['gold', 2.5, true]]];
        yield 'empty list member' => [['value' => []], ['value' => []]];
        yield 'null' => [['value' => null], ['value' => null]];
        yield 'integer member' => [['label' => 7], ['label' => 7]];
        yield 'list of strings member' => [['label' => ['a', 'b']], ['label' => ['a', 'b']]];
        yield 'enum string member' => [['mode' => 'auto'], ['mode' => 'auto']];
        yield 'enum integer member' => [['mode' => 5], ['mode' => 5]];
        yield 'top-level date member' => [['due' => '2026-01-02'], ['due' => '2026-01-02']];
        yield 'top-level boolean beside a date' => [['due' => true], ['due' => true]];
        yield 'top-level date-time member' => [['at' => '2026-01-02T03:04:05+00:00'], ['at' => '2026-01-02T03:04:05+00:00']];
        yield 'date member inside an object' => [['extra' => ['limit' => '2026-03-04']], ['extra' => ['limit' => '2026-03-04']]];
        yield 'integer member inside an object' => [['extra' => ['limit' => 7]], ['extra' => ['limit' => 7]]];
    }

    /**
     * @param array<string, mixed> $attributes
     * @param array<string, mixed> $expected
     */
    #[Test]
    #[Group('spec:crud')]
    #[\PHPUnit\Framework\Attributes\DataProvider('roundTrips')]
    public function eachMemberRoundTrips(array $attributes, array $expected): void
    {
        $created = $this->create($attributes);
        $created->assertStatus(201);

        $id = $created->json('data.id');
        self::assertIsString($id);
        $read = $this->getJson('/api/presets/' . $id, ['Accept' => self::MEDIA_TYPE]);
        $read->assertStatus(200);

        foreach ($expected as $name => $value) {
            self::assertSame($value, $created->json('data.attributes.' . $name), 'create: ' . $name);
            self::assertSame($value, $read->json('data.attributes.' . $name), 'read: ' . $name);
        }
    }

    #[Test]
    #[Group('spec:crud')]
    public function anUpdateStoresAnotherMember(): void
    {
        $response = $this->update(['value' => ['x', 1]]);

        $response->assertStatus(200);
        self::assertSame(['x', 1], $response->json('data.attributes.value'));
        self::assertSame(['x', 1], $this->getJson('/api/presets/1', ['Accept' => self::MEDIA_TYPE])->json('data.attributes.value'));
    }

    /**
     * @return iterable<string, array{array<string, mixed>, string}>
     */
    public static function memberViolations(): iterable
    {
        yield 'string member maxLength' => [['value' => \str_repeat('x', 21)], '/data/attributes/value'];
        yield 'list member maxItems' => [['value' => ['a', 'b', 'c', 'd']], '/data/attributes/value'];
        yield 'list element member maxLength' => [['value' => ['ok', \str_repeat('x', 11)]], '/data/attributes/value/1'];
        yield 'enum string member' => [['mode' => 'banana'], '/data/attributes/mode'];
        yield 'integer member minimum' => [['mode' => -1], '/data/attributes/mode'];
        yield 'top-level date member' => [['due' => '2026-13-45'], '/data/attributes/due'];
        yield 'date member inside an object' => [['extra' => ['limit' => 'banana']], '/data/attributes/extra/limit'];
        yield 'integer member inside an object' => [['extra' => ['limit' => 101]], '/data/attributes/extra/limit'];
    }

    /**
     * @param array<string, mixed> $attributes
     */
    #[Test]
    #[Group('spec:crud')]
    #[\PHPUnit\Framework\Attributes\DataProvider('memberViolations')]
    public function theSelectedMembersRulesApplyAtItsPointer(array $attributes, string $pointer): void
    {
        $response = $this->create($attributes);

        $response->assertStatus(422);
        self::assertSame([$pointer], $this->pointersOf($response));
        self::assertSame(['VALIDATION_FAILED'], $this->codesOf($response));
    }

    #[Test]
    #[Group('spec:crud')]
    public function theMemberRulesApplyOnAnUpdate(): void
    {
        $response = $this->update(['value' => ['ok', \str_repeat('x', 11)], 'mode' => 'banana']);

        $response->assertStatus(422);
        self::assertSame(['/data/attributes/value/1', '/data/attributes/mode'], $this->pointersOf($response));
    }

    /**
     * @return iterable<string, array{array<string, mixed>, string}>
     */
    public static function typeMismatches(): iterable
    {
        yield 'object for the union' => [['value' => ['a' => 1]], '/data/attributes/value'];
        yield 'fraction for an integer member' => [['label' => 1.5], '/data/attributes/label'];
        yield 'object for a list element' => [['value' => ['ok', ['a' => 1]]], '/data/attributes/value/1'];
        yield 'list for a list element' => [['value' => [['a']]], '/data/attributes/value/0'];
        yield 'null for a list element' => [['value' => [null]], '/data/attributes/value/0'];
        yield 'string for an integer-only object member' => [['extra' => ['limit' => true]], '/data/attributes/extra/limit'];
    }

    /**
     * @param array<string, mixed> $attributes
     */
    #[Test]
    #[Group('spec:crud')]
    #[\PHPUnit\Framework\Attributes\DataProvider('typeMismatches')]
    public function aValueNoMemberTakesIsOneErrorAtItsPointer(array $attributes, string $pointer): void
    {
        $response = $this->create($attributes);

        $response->assertStatus(422);
        self::assertSame([$pointer], $this->pointersOf($response));
        self::assertSame(['VALIDATION_FAILED'], $this->codesOf($response));
    }

    #[Test]
    #[Group('spec:crud')]
    public function theMismatchDetailNamesEveryTypeTheUnionTakes(): void
    {
        $response = $this->create(['value' => ['a' => 1], 'label' => 1.5, 'mode' => ['auto'], 'due' => 3]);

        $response->assertStatus(422);
        self::assertSame([
            '/data/attributes/due' => 'The due field must be a string or a boolean.',
            '/data/attributes/label' => 'The label field must be a string, an integer, a boolean or a list.',
            '/data/attributes/mode' => 'The mode field must be a string or an integer.',
            '/data/attributes/value' => 'The value field must be a string, a number, a boolean or a list.',
        ], $this->detailsOf($response));
    }

    /**
     * @return iterable<string, array{mixed, int}>
     */
    public static function requiredValues(): iterable
    {
        yield 'empty string' => ['', 422];
        yield 'empty list' => [[], 422];
        yield 'null' => [null, 422];
        yield 'false' => [false, 201];
        yield 'zero' => [0, 201];
        yield 'string' => ['x', 201];
    }

    #[Test]
    #[Group('spec:crud')]
    #[\PHPUnit\Framework\Attributes\DataProvider('requiredValues')]
    public function aRequiredUnionRefusesOnlyAnEmptyStringOrList(mixed $label, int $status): void
    {
        $response = $this->create(['label' => $label]);

        $response->assertStatus($status);
        if ($status === 422) {
            self::assertSame(['/data/attributes/label'], $this->pointersOf($response));
            self::assertSame(['VALIDATION_FAILED'], $this->codesOf($response));
        } else {
            self::assertSame($label, $response->json('data.attributes.label'));
        }
    }

    #[Test]
    #[Group('spec:crud')]
    public function anEmptyValueForARequiredUnionIsOneRequiredError(): void
    {
        $response = $this->create(['label' => '']);

        self::assertSame(['/data/attributes/label' => 'The label field is required.'], $this->detailsOf($response));
    }

    #[Test]
    #[Group('spec:crud')]
    public function aRequiredUnionMustBeSentOnCreate(): void
    {
        $response = $this->writeJsonApi('POST', '/api/presets', ['data' => ['type' => 'presets', 'attributes' => ['value' => 'gold']]]);

        $response->assertStatus(422);
        self::assertSame(['/data/attributes/label' => 'The label field is required.'], $this->detailsOf($response));
    }

    #[Test]
    #[Group('spec:crud')]
    public function anUpdateKeepsTheRequiredSemantics(): void
    {
        $refused = $this->update(['label' => []]);
        $refused->assertStatus(422);
        self::assertSame(['/data/attributes/label'], $this->pointersOf($refused));

        $accepted = $this->update(['label' => 0]);
        $accepted->assertStatus(200);
        self::assertSame(0, $accepted->json('data.attributes.label'));
    }

    #[Test]
    #[Group('spec:crud')]
    public function nullIsRefusedWhereTheUnionIsNotNullable(): void
    {
        $response = $this->create(['mode' => null]);

        $response->assertStatus(422);
        self::assertSame(['/data/attributes/mode'], $this->pointersOf($response));
        self::assertSame(['VALIDATION_FAILED'], $this->codesOf($response));
    }

    #[Test]
    #[Group('spec:crud')]
    public function nullIsStoredWhereTheUnionIsNullable(): void
    {
        $response = $this->create(['value' => null, 'due' => null, 'extra' => null]);

        $response->assertStatus(201);
        self::assertNull($response->json('data.attributes.value'));
        self::assertNull($response->json('data.attributes.due'));
    }

    #[Test]
    #[Group('openapi')]
    public function theOpenApiDocumentProjectsBothForms(): void
    {
        $document = $this->resolve(DocumentFactory::class)->forServer('default')->toArray();

        self::assertSame(['contract' => GeneratorContract::UNIONS], $this->arrayAt($document, 'info', 'x-generator'));
        $attributes = $this->arrayAt($document, 'components', 'schemas', 'PresetsCreateAttributes', 'properties');
        self::assertSame([
            'type' => ['string', 'number', 'boolean', 'array', 'null'],
            'maxLength' => 20,
            'maxItems' => 3,
            'items' => ['type' => ['string', 'number', 'boolean'], 'maxLength' => 10],
        ], $this->arrayAt($attributes, 'value'));
        self::assertSame([
            'oneOf' => [
                ['type' => 'string', 'enum' => ['auto', 'off']],
                ['type' => 'integer', 'minimum' => 0],
            ],
        ], $this->arrayAt($attributes, 'mode'));
        self::assertSame([
            'type' => ['string', 'integer', 'boolean', 'array'],
            'minLength' => 1,
            'maxLength' => 20,
            'minItems' => 1,
            'items' => ['type' => 'string'],
        ], $this->arrayAt($attributes, 'label'));
    }

    /**
     * Creates a preset and returns its id.
     *
     * @param array<string, mixed> $attributes
     */
    protected function createdId(array $attributes): string
    {
        $response = $this->create($attributes);
        $response->assertStatus(201);
        $id = $response->json('data.id');
        self::assertIsString($id);

        return $id;
    }

    /**
     * @param array<string, mixed> $attributes
     *
     * @return TestResponse<\Symfony\Component\HttpFoundation\Response>
     */
    private function create(array $attributes): TestResponse
    {
        return $this->writeJsonApi('POST', '/api/presets', [
            'data' => ['type' => 'presets', 'attributes' => ['label' => 'Preset', ...$attributes]],
        ]);
    }

    /**
     * @param array<string, mixed> $attributes
     *
     * @return TestResponse<\Symfony\Component\HttpFoundation\Response>
     */
    private function update(array $attributes): TestResponse
    {
        return $this->writeJsonApi('PATCH', '/api/presets/1', [
            'data' => ['type' => 'presets', 'id' => '1', 'attributes' => $attributes],
        ]);
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

    /**
     * @param TestResponse<\Symfony\Component\HttpFoundation\Response> $response
     *
     * @return list<mixed>
     */
    private function pointersOf(TestResponse $response): array
    {
        return \array_map(fn(array $error): mixed => $this->pointerOf($error), $this->errorsOf($response));
    }

    /**
     * @param TestResponse<\Symfony\Component\HttpFoundation\Response> $response
     *
     * @return list<mixed>
     */
    private function codesOf(TestResponse $response): array
    {
        return \array_map(static fn(array $error): mixed => $error['code'] ?? null, $this->errorsOf($response));
    }

    /**
     * Each error's detail by its pointer, sorted by pointer.
     *
     * @param TestResponse<\Symfony\Component\HttpFoundation\Response> $response
     *
     * @return array<string, mixed>
     */
    private function detailsOf(TestResponse $response): array
    {
        $details = [];
        foreach ($this->errorsOf($response) as $error) {
            $pointer = $this->pointerOf($error);
            self::assertIsString($pointer);
            $details[$pointer] = $error['detail'] ?? null;
        }
        \ksort($details);

        return $details;
    }

    /**
     * @param array<string, mixed> $error
     */
    private function pointerOf(array $error): mixed
    {
        $source = $error['source'] ?? null;

        return \is_array($source) ? ($source['pointer'] ?? null) : null;
    }

    /**
     * @param TestResponse<\Symfony\Component\HttpFoundation\Response> $response
     *
     * @return list<array<string, mixed>>
     */
    private function errorsOf(TestResponse $response): array
    {
        $errors = $response->json('errors');
        self::assertIsArray($errors);
        $list = [];
        foreach ($errors as $error) {
            self::assertIsArray($error);
            /** @var array<string, mixed> $error */
            $list[] = $error;
        }

        return $list;
    }
}
