<?php

declare(strict_types=1);

namespace haddowg\JsonApiLaravel\Tests\Feature\DateMessage;

use haddowg\JsonApiLaravel\JsonApiServiceProvider;
use haddowg\JsonApiLaravel\Testing\InteractsWithJsonApi;
use haddowg\JsonApiLaravel\Tests\Fixtures\DateMessage\DateMessageServiceProvider;
use Illuminate\Testing\TestResponse;
use Orchestra\Testbench\TestCase as Orchestra;
use PHPUnit\Framework\Attributes\Test;

/**
 * A bad date reads as core's one message for its kind, whether it is an attribute write
 * or a date range bound: the attribute named by its path below `/data/attributes`, the
 * bound by its parameter, and the example the declared one or else the fixed one. Runs on
 * Eloquent ({@see EloquentDateMessageTest}) and the in-memory witness
 * ({@see InMemoryDateMessageTest}).
 */
abstract class DateMessageTestCase extends Orchestra
{
    use InteractsWithJsonApi;

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
        $config->set('date_message.store', $this->store());

        return [JsonApiServiceProvider::class, DateMessageServiceProvider::class];
    }

    /**
     * @param \Illuminate\Foundation\Application $app
     */
    protected function defineEnvironment($app): void
    {
        /** @var \Illuminate\Contracts\Config\Repository $config */
        $config = $app['config'];
        $config->set('app.debug', false);
        $config->set('logging.default', 'null');
        $config->set('database.default', 'testing');
        $config->set('database.connections.testing', ['driver' => 'sqlite', 'database' => ':memory:', 'prefix' => '']);
    }

    protected function defineDatabaseMigrations(): void
    {
        if ($this->store() === 'eloquent') {
            DateMessageServiceProvider::migrate();
        }
    }

    /**
     * @return array<string, array{mixed, string, string}>
     */
    private static function badAttributes(): array
    {
        return [
            'startsAt' => ['last year', 'startsAt is not an RFC 3339 date-time with an offset, such as 2026-10-01T00:00:00Z.', '/data/attributes/startsAt'],
            'endsAt' => ['2026-11-05 18:00', 'endsAt is not an RFC 3339 date-time with an offset, such as 2026-11-05T18:00:00+01:00.', '/data/attributes/endsAt'],
            'day' => ['2026-02-30', 'day is not a date like 2026-10-01.', '/data/attributes/day'],
            'dueOn' => ['tomorrow', 'dueOn is not a date like 2026-12-24.', '/data/attributes/dueOn'],
            'opensAt' => ['09:00:00', 'opensAt is not a time like 09:42:00Z.', '/data/attributes/opensAt'],
            'closesAt' => ['5pm', 'closesAt is not a time like 17:30:00+01:00.', '/data/attributes/closesAt'],
            'breakAt' => ['12:30', 'breakAt is not a time like 09:08:07.', '/data/attributes/breakAt'],
            'window' => [['from' => '2026-10-01'], 'window/from is not an RFC 3339 date-time with an offset, such as 2026-10-01T00:00:00Z.', '/data/attributes/window/from'],
            'until' => ['2026-02-30', 'until is not a date like 2026-10-01.', '/data/attributes/until'],
            'marks' => [['first' => 'soon'], 'marks/first is not a date like 2026-10-01.', '/data/attributes/marks/first'],
        ];
    }

    #[Test]
    public function aBadDateOnCreateIsOneErrorWithItsKindsMessage(): void
    {
        foreach (self::badAttributes() as $name => [$value, $detail, $pointer]) {
            $response = $this->sendJsonApiRequest('POST', '/api/dmBookings', ['data' => ['type' => 'dmBookings', 'attributes' => [$name => $value]]], []);

            $this->assertOneError($response, 422, 'VALIDATION_FAILED', $detail, ['pointer' => $pointer], $name);
        }
    }

    #[Test]
    public function aBadDateOnUpdateIsOneErrorWithItsKindsMessage(): void
    {
        foreach (self::badAttributes() as $name => [$value, $detail, $pointer]) {
            $response = $this->sendJsonApiRequest('PATCH', '/api/dmBookings/1', ['data' => ['type' => 'dmBookings', 'id' => '1', 'attributes' => [$name => $value]]], []);

            $this->assertOneError($response, 422, 'VALIDATION_FAILED', $detail, ['pointer' => $pointer], $name);
        }
    }

    #[Test]
    public function goodDatesAreStillWritten(): void
    {
        $response = $this->sendJsonApiRequest('POST', '/api/dmBookings', ['data' => ['type' => 'dmBookings', 'attributes' => [
            'startsAt' => '2026-10-01T09:00:00Z',
            'day' => '2026-10-01',
        ]]], []);

        self::assertSame(201, $response->getStatusCode(), (string) $response->getContent());
    }

    #[Test]
    public function aBadDateRangeBoundIsOneErrorNamingItsParameter(): void
    {
        $cases = [
            'filter[startsRange][min]=banana' => ['startsRange', 'filter[startsRange][min] is not an RFC 3339 date-time with an offset, such as 2026-10-01T00:00:00Z.'],
            'filter[startsRange][max]=2026-10-01' => ['startsRange', 'filter[startsRange][max] is not an RFC 3339 date-time with an offset, such as 2026-10-01T00:00:00Z.'],
            'filter[startsRange][min]=2026-02-30T00:00:00Z' => ['startsRange', 'filter[startsRange][min] is not an RFC 3339 date-time with an offset, such as 2026-10-01T00:00:00Z.'],
            'filter[dayRange][min]=2026-10-01T00:00:00Z' => ['dayRange', 'filter[dayRange][min] is not a date like 2026-10-01.'],
            'filter[dayRange][max]=2026-02-30' => ['dayRange', 'filter[dayRange][max] is not a date like 2026-10-01.'],
            'filter[endsRange][min]=soon' => ['endsRange', 'filter[endsRange][min] is not an RFC 3339 date-time with an offset, such as 2026-01-01T00:00:00Z.'],
            'filter[endsRange][max]=later' => ['endsRange', 'filter[endsRange][max] is not an RFC 3339 date-time with an offset, such as 2026-12-31T23:59:59Z.'],
        ];

        foreach ($cases as $query => [$key, $detail]) {
            $response = $this->sendJsonApiRequest('GET', '/api/dmBookings?' . $query, null, []);

            $this->assertOneError($response, 400, 'FILTER_VALUE_INVALID', $detail, ['parameter' => 'filter[' . $key . ']'], $query);
        }
    }

    #[Test]
    public function eachBadBoundIsItsOwnError(): void
    {
        $response = $this->sendJsonApiRequest('GET', '/api/dmBookings?filter[dayRange][min]=banana&filter[dayRange][max]=2026-02-30', null, []);

        self::assertSame(400, $response->getStatusCode(), (string) $response->getContent());
        self::assertSame(
            ['filter[dayRange][min] is not a date like 2026-10-01.', 'filter[dayRange][max] is not a date like 2026-10-01.'],
            $response->json('errors.*.detail'),
        );
    }

    #[Test]
    public function goodBoundsStillFilter(): void
    {
        $response = $this->sendJsonApiRequest('GET', '/api/dmBookings?filter[startsRange][min]=2026-09-30T00:00:00Z&filter[dayRange][max]=2026-10-01', null, []);

        self::assertSame(200, $response->getStatusCode(), (string) $response->getContent());
        self::assertSame(['1'], $response->json('data.*.id'));
    }

    /**
     * @param TestResponse<\Symfony\Component\HttpFoundation\Response> $response
     * @param array<string, string>                                     $source
     */
    private function assertOneError(TestResponse $response, int $status, string $code, string $detail, array $source, string $case): void
    {
        self::assertSame($status, $response->getStatusCode(), $case . ': ' . $response->getContent());
        $errors = $response->json('errors');
        self::assertIsArray($errors, $case);
        self::assertCount(1, $errors, $case . ': ' . $response->getContent());
        self::assertSame($code, $response->json('errors.0.code'), $case);
        self::assertSame($detail, $response->json('errors.0.detail'), $case);
        self::assertSame($source, $response->json('errors.0.source'), $case);
    }
}
