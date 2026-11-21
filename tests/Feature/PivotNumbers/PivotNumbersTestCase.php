<?php

declare(strict_types=1);

namespace haddowg\JsonApiLaravel\Tests\Feature\PivotNumbers;

use haddowg\JsonApiLaravel\Tests\Fixtures\PivotNumbers\TallyResource;
use Illuminate\Testing\TestResponse;
use Orchestra\Testbench\TestCase as Orchestra;
use PHPUnit\Framework\Attributes\CoversNothing;
use PHPUnit\Framework\Attributes\Test;

/**
 * A pivot value reaches a write in its field's type: a whole number sent to an integer pivot
 * field as `5.0` arrives as an `int`, and an integer sent to a number pivot field as a
 * `float`, on every path that carries linkage with pivot meta. A value that is not a number
 * is refused at its pointer on each of them.
 *
 * @internal
 */
#[CoversNothing]
abstract class PivotNumbersTestCase extends Orchestra
{
    private const string MEDIA_TYPE = 'application/vnd.api+json';

    private const string ATOMIC_MEDIA_TYPE = 'application/vnd.api+json; ext="https://jsonapi.org/ext/atomic"';

    private const array MARK = ['type' => 'notes', 'id' => '1', 'meta' => ['pivot' => ['rank' => 5.0, 'weight' => 5]]];

    private const array NORMALIZED = [['rank' => 'int', 'weight' => 'float']];

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

    protected function setUp(): void
    {
        parent::setUp();
        TallyResource::$received = [];
    }

    #[Test]
    public function anAddToTheRelationshipNormalizesEachPivotValue(): void
    {
        $this->send('POST', '/api/tallies/1/relationships/marks', ['data' => [self::MARK]])->assertSuccessful();

        self::assertSame(self::NORMALIZED, TallyResource::$received);
    }

    #[Test]
    public function aReplaceOfTheRelationshipNormalizesEachPivotValue(): void
    {
        $this->send('PATCH', '/api/tallies/1/relationships/marks', ['data' => [self::MARK]])->assertSuccessful();

        self::assertSame(self::NORMALIZED, TallyResource::$received);
    }

    #[Test]
    public function aCreateCarryingTheRelationshipNormalizesEachPivotValue(): void
    {
        $this->send('POST', '/api/tallies', $this->tally(['name' => 'Chores']))->assertCreated();

        self::assertSame(self::NORMALIZED, TallyResource::$received);
    }

    #[Test]
    public function anUpdateCarryingTheRelationshipNormalizesEachPivotValue(): void
    {
        $this->send('PATCH', '/api/tallies/1', $this->tally(['name' => 'Chores'], '1'))->assertOk();

        self::assertSame(self::NORMALIZED, TallyResource::$received);
    }

    #[Test]
    public function anAtomicOperationNormalizesEachPivotValue(): void
    {
        $this->atomic([
            ['op' => 'add', 'data' => $this->tally(['name' => 'Chores'])['data']],
            ['op' => 'add', 'ref' => ['type' => 'tallies', 'id' => '1', 'relationship' => 'marks'], 'data' => [self::MARK]],
        ])->assertSuccessful();

        self::assertSame([...self::NORMALIZED, ...self::NORMALIZED], TallyResource::$received);
    }

    #[Test]
    public function anActionsInputNormalizesEachPivotValue(): void
    {
        $response = $this->send('POST', '/api/tallies/-actions/score', $this->tally(['name' => 'Chores']));

        $response->assertOk();
        self::assertSame(self::NORMALIZED, $response->json('meta.received'));
    }

    #[Test]
    public function aPatchActionsInputNormalizesEachPivotValue(): void
    {
        $response = $this->send('PATCH', '/api/tallies/-actions/revise', $this->tally(['name' => 'Chores'], '1'));

        $response->assertOk();
        self::assertSame(self::NORMALIZED, $response->json('meta.received'));
    }

    #[Test]
    public function anActionAcceptingSeveralBodiesNormalizesItsDocument(): void
    {
        $response = $this->send('POST', '/api/tallies/-actions/import', $this->tally(['name' => 'Chores']));

        $response->assertOk();
        self::assertSame('document', $response->json('meta.input'));
        self::assertSame(self::NORMALIZED, $response->json('meta.received'));
    }

    #[Test]
    public function anActionsRawBodyIsLeftAsSent(): void
    {
        $response = $this->call('POST', '/api/tallies/-actions/import', [], [], [], [
            'HTTP_ACCEPT' => self::MEDIA_TYPE,
            'CONTENT_TYPE' => 'text/csv',
        ], "rank,weight\n5.0,5\n");

        $response->assertOk();
        self::assertSame("rank,weight\n5.0,5\n", $response->json('meta.raw'));
        self::assertSame([], $response->json('meta.received'));
    }

    #[Test]
    public function aNonNumberPivotValueIsRefusedOnTheRelationshipEndpoint(): void
    {
        $this->send('POST', '/api/tallies/1/relationships/marks', ['data' => [$this->mark('heavy')]])
            ->assertStatus(422)
            ->assertJsonPath('errors.0.source.pointer', '/data/0/meta/pivot/weight');
        self::assertSame([], TallyResource::$received);
    }

    #[Test]
    public function aNonNumberPivotValueIsRefusedOnACreate(): void
    {
        $this->send('POST', '/api/tallies', $this->tally(['name' => 'Chores'], mark: $this->mark('heavy')))
            ->assertStatus(422)
            ->assertJsonPath('errors.0.source.pointer', '/data/relationships/marks/data/0/meta/pivot/weight');
        self::assertSame([], TallyResource::$received);
    }

    #[Test]
    public function aNonNumberPivotValueIsRefusedInAnAtomicOperation(): void
    {
        $this->atomic([
            ['op' => 'add', 'ref' => ['type' => 'tallies', 'id' => '1', 'relationship' => 'marks'], 'data' => [$this->mark('heavy')]],
        ])
            ->assertStatus(422)
            ->assertJsonPath('errors.0.source.pointer', '/atomic:operations/0/data/0/meta/pivot/weight');
        self::assertSame([], TallyResource::$received);
    }

    #[Test]
    public function aNonNumberPivotValueIsRefusedInAnActionsInput(): void
    {
        $this->send('POST', '/api/tallies/-actions/score', $this->tally(['name' => 'Chores'], mark: $this->mark('heavy')))
            ->assertStatus(422)
            ->assertJsonPath('errors.0.source.pointer', '/data/relationships/marks/data/0/meta/pivot/weight');
        self::assertSame([], TallyResource::$received);
    }

    #[Test]
    public function aWholeFloatIsTheOnlyFloatAnIntegerPivotFieldTakesInAnActionsInput(): void
    {
        $this->send('POST', '/api/tallies/-actions/score', $this->tally(['name' => 'Chores'], mark: ['type' => 'notes', 'id' => '1', 'meta' => ['pivot' => ['rank' => 5.5]]]))
            ->assertStatus(422)
            ->assertJsonPath('errors.0.source.pointer', '/data/relationships/marks/data/0/meta/pivot/rank');
    }

    #[Test]
    public function anActionsInputWhoseAttributesIsNotAnObjectIsStillValidated(): void
    {
        $document = $this->tally([]);
        $document['data']['attributes'] = 'none';

        $this->send('POST', '/api/tallies/-actions/score', $document)
            ->assertStatus(422)
            ->assertJsonPath('errors.0.source.pointer', '/data/attributes/name');
        self::assertSame([], TallyResource::$received);
    }

    /**
     * A `tallies` document marking note 1.
     *
     * @param array<string, mixed>  $attributes
     * @param array<string, mixed>  $mark
     *
     * @return array{data: array<string, mixed>}
     */
    private function tally(array $attributes, ?string $id = null, array $mark = self::MARK): array
    {
        $data = ['type' => 'tallies'];
        if ($id !== null) {
            $data['id'] = $id;
        }
        if ($attributes !== []) {
            $data['attributes'] = $attributes;
        }
        $data['relationships'] = ['marks' => ['data' => [$mark]]];

        return ['data' => $data];
    }

    /**
     * A mark on note 1 with `$weight` as its number pivot value.
     *
     * @return array<string, mixed>
     */
    private function mark(mixed $weight): array
    {
        return ['type' => 'notes', 'id' => '1', 'meta' => ['pivot' => ['rank' => 5, 'weight' => $weight]]];
    }

    /**
     * Sends the document keeping a whole float's `.0`, as a client writing `5.0` sends it.
     *
     * @param array<string, mixed> $document
     *
     * @return TestResponse<\Symfony\Component\HttpFoundation\Response>
     */
    private function send(string $method, string $uri, array $document, string $mediaType = self::MEDIA_TYPE): TestResponse
    {
        return $this->call($method, $uri, [], [], [], [
            'HTTP_ACCEPT' => $mediaType,
            'CONTENT_TYPE' => $mediaType,
        ], \json_encode($document, \JSON_THROW_ON_ERROR | \JSON_PRESERVE_ZERO_FRACTION));
    }

    /**
     * @param list<array<string, mixed>> $operations
     *
     * @return TestResponse<\Symfony\Component\HttpFoundation\Response>
     */
    private function atomic(array $operations): TestResponse
    {
        return $this->send('POST', '/api/operations', ['atomic:operations' => $operations], self::ATOMIC_MEDIA_TYPE);
    }
}
