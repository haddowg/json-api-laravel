<?php

declare(strict_types=1);

namespace haddowg\JsonApiLaravel\Tests\Conformance;

use haddowg\JsonApiLaravel\JsonApiServiceProvider;
use haddowg\JsonApiLaravel\Testing\InteractsWithJsonApi;
use Illuminate\Testing\TestResponse;
use Orchestra\Testbench\TestCase as Orchestra;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\Test;
use Workbench\App\StorageTimezone\ShiftFixtures;

/**
 * Date bounds and date writes against shifts stored as local New York time, with the
 * default timezone set to New York for each test.
 *
 * The same assertions run on the in-memory witness, which compares instants, and on
 * Eloquent, whose datetime casts store wall-clock strings. A bound sent in `Z` or
 * another offset has to select the same rows on both. Shift 1 starts at 16:00Z,
 * shift 2 at 14:00Z and shift 3 at 13:00Z the next day.
 */
abstract class StorageTimezoneConformanceTestCase extends Orchestra
{
    use InteractsWithJsonApi;

    private string $previousTimezone;

    /**
     * @return class-string
     */
    abstract protected function conformanceServiceProvider(): string;

    protected function getPackageProviders($app): array
    {
        return [
            JsonApiServiceProvider::class,
            $this->conformanceServiceProvider(),
        ];
    }

    protected function defineEnvironment($app): void
    {
        $config = $app['config'];
        $config->set('app.timezone', ShiftFixtures::ZONE);
        $config->set('jsonapi.base_uri', 'http://localhost/api');
        $config->set('jsonapi.output_timezone', 'UTC');
    }

    protected function seedConformanceData(): void {}

    protected function setUp(): void
    {
        $this->previousTimezone = \date_default_timezone_get();

        parent::setUp();

        \date_default_timezone_set(ShiftFixtures::ZONE);
        $this->seedConformanceData();
    }

    protected function tearDown(): void
    {
        parent::tearDown();

        \date_default_timezone_set($this->previousTimezone);
    }

    #[Test]
    #[Group('spec:fetching-filtering')]
    public function aDateRangeBoundInUtcComparesTheInstantNotTheWallClock(): void
    {
        // 15:00Z is 11:00 in New York, so the 12:00 local shift is included and the
        // 10:00 one is not. Comparing 15:00 against the stored local times would drop
        // shift 1.
        self::assertSame(['1', '3'], $this->sortedIds('/api/shifts?filter[startsRange][min]=2026-10-01T15:00:00Z'));
        self::assertSame(['2'], $this->sortedIds('/api/shifts?filter[startsRange][max]=2026-10-01T15:00:00Z'));
    }

    #[Test]
    #[Group('spec:fetching-filtering')]
    public function aDateRangeBoundWithAnotherOffsetComparesTheInstant(): void
    {
        self::assertSame(['1', '3'], $this->sortedIds('/api/shifts?filter[startsRange][min]=' . \rawurlencode('2026-10-01T17:00:00+02:00')));
        self::assertSame(['1'], $this->sortedIds('/api/shifts?filter[startsRange][min]=' . \rawurlencode('2026-10-01T11:30:00-04:00') . '&filter[startsRange][max]=2026-10-01T16:00:00Z'));
    }

    #[Test]
    #[Group('spec:fetching-filtering')]
    public function aBoundWithoutAnOffsetIsReadAsLocalTime(): void
    {
        self::assertSame(['1', '3'], $this->sortedIds('/api/shifts?filter[startsRange][min]=2026-10-01T11:00:00'));
    }

    #[Test]
    #[Group('spec:fetching-filtering')]
    public function aWhereFilterWithADateValueComparesTheInstant(): void
    {
        self::assertSame(['1', '3'], $this->sortedIds('/api/shifts?filter[startsFrom]=2026-10-01T15:00:00Z'));
        self::assertSame(['3'], $this->sortedIds('/api/shifts?filter[startsFrom]=2026-10-01T16:00:01Z'));
    }

    #[Test]
    #[Group('spec:fetching-filtering')]
    public function aDateColumnComparesTheDayTheClientWrote(): void
    {
        // Moving midnight UTC into New York would make it 30 September and pull in
        // shift 2, so a date column keeps the bound's own calendar day.
        self::assertSame(['1', '3'], $this->sortedIds('/api/shifts?filter[dayRange][min]=2026-10-01'));
        self::assertSame(['1', '3'], $this->sortedIds('/api/shifts?filter[dayRange][min]=2026-10-01T00:00:00Z'));
        self::assertSame(['1', '2'], $this->sortedIds('/api/shifts?filter[dayRange][max]=2026-10-01'));
    }

    #[Test]
    #[Group('crud:create')]
    public function aWriteWithAnOffsetRoundTripsAsTheSameInstant(): void
    {
        $created = $this->write('POST', '/api/shifts', ['data' => [
            'type' => 'shifts',
            'attributes' => ['startsAt' => '2026-10-03T16:00:00Z', 'day' => '2026-10-03'],
        ]]);
        $created->assertCreated();
        $created->assertJsonPath('data.attributes.startsAt', '2026-10-03T16:00:00+00:00');
        $created->assertJsonPath('data.attributes.day', '2026-10-03');

        $id = $created->json('data.id');
        self::assertIsString($id);

        $this->jsonApi()->get('/api/shifts/' . $id)
            ->assertOk()
            ->assertJsonPath('data.attributes.startsAt', '2026-10-03T16:00:00+00:00');

        self::assertSame([$id], $this->sortedIds('/api/shifts?filter[startsRange][min]=' . \rawurlencode('2026-10-03T12:00:00-04:00') . '&filter[startsRange][max]=2026-10-03T16:00:00Z'));
    }

    #[Test]
    #[Group('crud:update')]
    public function anUpdateWithAnOffsetRoundTripsAsTheSameInstant(): void
    {
        $this->write('PATCH', '/api/shifts/2', ['data' => [
            'type' => 'shifts',
            'id' => '2',
            'attributes' => ['startsAt' => '2026-10-01T18:30:00+02:00'],
        ]])->assertOk();

        $this->jsonApi()->get('/api/shifts/2')
            ->assertOk()
            ->assertJsonPath('data.attributes.startsAt', '2026-10-01T16:30:00+00:00');
        self::assertSame(['1', '2', '3'], $this->sortedIds('/api/shifts?filter[startsRange][min]=2026-10-01T16:00:00Z'));
    }

    #[Test]
    #[Group('spec:fetching-filtering')]
    #[Group('spec:errors')]
    public function anImpossibleDateBoundIsA400(): void
    {
        foreach (['2026-02-30T00:00:00Z', '2026-04-31', '2026-02-28T24:00:00Z'] as $bound) {
            $response = $this->jsonApi()->get('/api/shifts?filter[startsRange][min]=' . \rawurlencode($bound));

            $response->assertStatus(400);
            self::assertSame('FILTER_VALUE_INVALID', $response->json('errors.0.code'), $bound);
            self::assertSame('filter[startsRange]', $response->json('errors.0.source.parameter'));
        }
    }

    #[Test]
    #[Group('crud:create')]
    #[Group('spec:errors')]
    public function anImpossibleDateInAWriteIsA422AtTheAttribute(): void
    {
        $response = $this->write('POST', '/api/shifts', ['data' => [
            'type' => 'shifts',
            'attributes' => ['startsAt' => '2026-02-30T00:00:00Z'],
        ]]);

        $response->assertStatus(422);
        self::assertSame('/data/attributes/startsAt', $response->json('errors.0.source.pointer'));
    }

    /**
     * @param array<string, mixed> $document
     *
     * @return TestResponse<\Symfony\Component\HttpFoundation\Response>
     */
    protected function write(string $method, string $uri, array $document): TestResponse
    {
        $request = $this->jsonApi()->withDocument($document);

        return $method === 'POST' ? $request->post($uri) : $request->patch($uri);
    }

    /**
     * @return list<string>
     */
    protected function sortedIds(string $uri): array
    {
        $response = $this->jsonApi()->get($uri);
        $response->assertOk();

        $ids = [];
        $data = $response->json('data');
        self::assertIsArray($data);
        foreach ($data as $resource) {
            self::assertIsArray($resource);
            $id = $resource['id'] ?? null;
            self::assertIsString($id);
            $ids[] = $id;
        }
        \sort($ids, \SORT_NUMERIC);

        return $ids;
    }
}
