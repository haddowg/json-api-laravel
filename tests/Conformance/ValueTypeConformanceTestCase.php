<?php

declare(strict_types=1);

namespace haddowg\JsonApiLaravel\Tests\Conformance;

use haddowg\JsonApiLaravel\JsonApiServiceProvider;
use Illuminate\Testing\TestResponse;
use Orchestra\Testbench\TestCase as Orchestra;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\Test;

/**
 * The JSON type witness, run against both providers, the Laravel twin of the Symfony
 * bundle's `ValueTypeConformanceTestCase`: a written value whose JSON type is not the one
 * the write schema documents for its field is a `422` at its pointer, the only error
 * there, instead of being cast. It holds at every depth, and `null` passes only where the
 * field is nullable. The detail is Laravel's own message for the type.
 */
abstract class ValueTypeConformanceTestCase extends Orchestra
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
    public function wellTypedValuesAreStored(): void
    {
        $response = $this->create([
            'count' => 3,
            'ratio' => 2.5,
            'flag' => false,
            'day' => '2026-01-02',
            'at' => '2026-01-02T03:04:05+00:00',
            'kind' => 'draft',
            'tags' => ['a', 'b'],
            'options' => ['notifyPublishers' => true, 'note' => null],
            'rows' => [['Key' => 'RFP-1', 'Vendor' => 'Acme']],
            'mapping' => ['entries' => [['target' => ['name' => 'Leads']]]],
            'block' => ['kind' => 'heading', 'text' => 'Hi', 'level' => 2],
        ]);

        $response->assertStatus(201);
        $attributes = $response->json('data.attributes');
        self::assertIsArray($attributes);
        self::assertSame(3, $attributes['count'] ?? null);
        self::assertSame(2.5, $attributes['ratio'] ?? null);
        self::assertFalse($attributes['flag'] ?? null);
        self::assertSame(['notifyPublishers' => true, 'note' => null], $attributes['options'] ?? null);
    }

    #[Test]
    #[Group('spec:crud')]
    public function nullIsStoredWhereTheFieldIsNullable(): void
    {
        $response = $this->create([
            'count' => null,
            'ratio' => null,
            'flag' => null,
            'day' => null,
            'at' => null,
            'kind' => null,
            'tags' => null,
            'options' => null,
            'rows' => null,
            'mapping' => null,
            'block' => null,
        ]);

        $response->assertStatus(201);
    }

    #[Test]
    #[Group('spec:crud')]
    public function theReportedPayloadsAreRefusedAtTheirPointers(): void
    {
        $payloads = [
            'notifyPublishers "yes"' => [['options' => ['notifyPublishers' => 'yes']], '/data/attributes/options/notifyPublishers'],
            'notifyPublishers 1' => [['options' => ['notifyPublishers' => 1]], '/data/attributes/options/notifyPublishers'],
            'notifyPublishers "false"' => [['options' => ['notifyPublishers' => 'false']], '/data/attributes/options/notifyPublishers'],
            'row Key 42' => [['rows' => [['Key' => 42]]], '/data/attributes/rows/0/Key'],
            'row Key true' => [['rows' => [['Key' => true]]], '/data/attributes/rows/0/Key'],
            'row Key a list of strings' => [['rows' => [['Key' => ['a', 'b']]]], '/data/attributes/rows/0/Key'],
            'open member 42' => [['rows' => [['Key' => 'RFP-1', 'Vendor' => 42]]], '/data/attributes/rows/0/Vendor'],
            'open member true' => [['rows' => [['Key' => 'RFP-1', 'Vendor' => true]]], '/data/attributes/rows/0/Vendor'],
            'mapping target name 42' => [['mapping' => ['entries' => [['target' => ['name' => 42]]]]], '/data/attributes/mapping/entries/0/target/name'],
        ];

        foreach ($payloads as $case => [$attributes, $pointer]) {
            $response = $this->create($attributes);

            self::assertSame(422, $response->getStatusCode(), $case . ': ' . $response->getContent());
            self::assertSame([$pointer], $this->pointersOf($response), $case);
            self::assertSame(['VALIDATION_FAILED'], $this->codesOf($response), $case);
        }
    }

    #[Test]
    #[Group('spec:crud')]
    public function theDetailIsLaravelsMessageForTheType(): void
    {
        $response = $this->create(['options' => ['notifyPublishers' => 'yes'], 'rows' => [['Key' => 42]], 'count' => '1', 'ratio' => '2', 'tags' => 'a']);

        $response->assertStatus(422);
        $details = [];
        foreach ($this->errorsOf($response) as $error) {
            $pointer = $this->at($error, 'source', 'pointer');
            self::assertIsString($pointer);
            $details[$pointer] = $this->at($error, 'detail');
        }
        \ksort($details);
        self::assertSame([
            '/data/attributes/count' => 'The count field must be an integer.',
            '/data/attributes/options/notifyPublishers' => 'The options.notify publishers field must be true or false.',
            '/data/attributes/ratio' => 'The ratio field must be a number.',
            '/data/attributes/rows/0/Key' => 'The rows.0.key field must be a string.',
            '/data/attributes/tags' => 'The tags field must be a list.',
        ], $details);
    }

    #[Test]
    #[Group('spec:crud')]
    public function everyTopLevelKindRefusesTheWrongType(): void
    {
        $response = $this->create([
            'name' => 42,
            'count' => '1',
            'ratio' => '1.5',
            'flag' => 'true',
            'day' => 20260102,
            'at' => 0,
            'kind' => 1,
            'tags' => 'a,b',
            'options' => 'all',
            'block' => true,
        ]);

        $response->assertStatus(422);
        self::assertSame([
            '/data/attributes/name',
            '/data/attributes/count',
            '/data/attributes/ratio',
            '/data/attributes/flag',
            '/data/attributes/day',
            '/data/attributes/at',
            '/data/attributes/kind',
            '/data/attributes/tags',
            '/data/attributes/options',
            '/data/attributes/block',
        ], $this->sortedPointersOf($response, ['name', 'count', 'ratio', 'flag', 'day', 'at', 'kind', 'tags', 'options', 'block']));
        self::assertSame(\array_fill(0, 10, 'VALIDATION_FAILED'), $this->codesOf($response));
    }

    #[Test]
    #[Group('spec:crud')]
    public function aValueOutOfItsBoundsAndOfTheWrongTypeGetsOneError(): void
    {
        $response = $this->create(['name' => 123456789012345678901234567890.0, 'count' => '-1', 'tags' => \array_fill(0, 6, 1)]);

        $response->assertStatus(422);
        self::assertSame(
            ['/data/attributes/name', '/data/attributes/count', '/data/attributes/tags', '/data/attributes/tags/0', '/data/attributes/tags/1', '/data/attributes/tags/2', '/data/attributes/tags/3', '/data/attributes/tags/4', '/data/attributes/tags/5'],
            $this->sortedPointersOf($response, ['name', 'count', 'tags']),
        );
    }

    #[Test]
    #[Group('spec:crud')]
    public function aListElementAndAVariantChildRefuseTheWrongType(): void
    {
        $response = $this->create([
            'tags' => ['a', 2, null],
            'rows' => ['RFP-1'],
            'block' => ['kind' => 'heading', 'text' => 'Hi', 'level' => '2'],
        ]);

        $response->assertStatus(422);
        self::assertSame(
            ['/data/attributes/tags/1', '/data/attributes/tags/2', '/data/attributes/rows/0', '/data/attributes/block/level'],
            $this->sortedPointersOf($response, ['tags', 'rows', 'block']),
        );
    }

    #[Test]
    #[Group('spec:crud')]
    public function nullIsRefusedWhereTheFieldIsNotNullable(): void
    {
        $response = $this->create([
            'options' => ['notifyPublishers' => null],
            'rows' => [['Key' => 'RFP-1', 'Vendor' => null]],
            'mapping' => ['entries' => [['target' => ['name' => null]]]],
        ]);

        $response->assertStatus(422);
        self::assertSame(
            ['/data/attributes/options/notifyPublishers', '/data/attributes/rows/0/Vendor', '/data/attributes/mapping/entries/0/target/name'],
            $this->sortedPointersOf($response, ['options', 'rows', 'mapping']),
        );
    }

    #[Test]
    #[Group('spec:crud')]
    public function anUpdateRefusesTheWrongType(): void
    {
        $response = $this->writeJsonApi('PATCH', '/api/submissions/1', [
            'data' => ['type' => 'submissions', 'id' => '1', 'attributes' => ['flag' => 1, 'options' => ['notifyPublishers' => 'no']]],
        ]);

        $response->assertStatus(422);
        self::assertSame(['/data/attributes/flag', '/data/attributes/options/notifyPublishers'], $this->pointersOf($response));
    }

    /**
     * @param array<string, mixed> $attributes
     *
     * @return TestResponse<\Symfony\Component\HttpFoundation\Response>
     */
    private function create(array $attributes): TestResponse
    {
        return $this->writeJsonApi('POST', '/api/submissions', [
            'data' => ['type' => 'submissions', 'attributes' => ['name' => 'Submission', ...$attributes]],
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
     * The error pointers, grouped by the attribute they fall under in `$order`, so a
     * check does not depend on the order the validator's passes report in.
     *
     * @param TestResponse<\Symfony\Component\HttpFoundation\Response> $response
     * @param list<string> $order
     *
     * @return list<mixed>
     */
    private function sortedPointersOf(TestResponse $response, array $order): array
    {
        $pointers = $this->pointersOf($response);
        $rank = static function (mixed $pointer) use ($order): int {
            foreach ($order as $index => $name) {
                if (\is_string($pointer) && ($pointer === '/data/attributes/' . $name || \str_starts_with($pointer, '/data/attributes/' . $name . '/'))) {
                    return $index;
                }
            }

            return \count($order);
        };
        \usort($pointers, static fn(mixed $a, mixed $b): int => [$rank($a), $a] <=> [$rank($b), $b]);

        return $pointers;
    }

    /**
     * @param TestResponse<\Symfony\Component\HttpFoundation\Response> $response
     *
     * @return list<mixed>
     */
    private function pointersOf(TestResponse $response): array
    {
        return \array_map(fn(mixed $error): mixed => $this->at($error, 'source', 'pointer'), $this->errorsOf($response));
    }

    /**
     * @param TestResponse<\Symfony\Component\HttpFoundation\Response> $response
     *
     * @return list<mixed>
     */
    private function codesOf(TestResponse $response): array
    {
        return \array_map(fn(mixed $error): mixed => $this->at($error, 'code'), $this->errorsOf($response));
    }

    /**
     * @param TestResponse<\Symfony\Component\HttpFoundation\Response> $response
     *
     * @return list<mixed>
     */
    private function errorsOf(TestResponse $response): array
    {
        $errors = $response->json('errors');
        self::assertIsArray($errors);

        return \array_values($errors);
    }

    /**
     * The value at a key path through nested arrays, or `null` when a step is missing.
     */
    private function at(mixed $value, string|int ...$keys): mixed
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
