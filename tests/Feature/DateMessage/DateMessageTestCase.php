<?php

declare(strict_types=1);

namespace haddowg\JsonApiLaravel\Tests\Feature\DateMessage;

use haddowg\JsonApiLaravel\JsonApiServiceProvider;
use haddowg\JsonApiLaravel\Testing\InteractsWithJsonApi;
use haddowg\JsonApiLaravel\Tests\Fixtures\DateMessage\DateMessageServiceProvider;
use Illuminate\Contracts\Translation\Translator;
use Illuminate\Testing\TestResponse;
use Orchestra\Testbench\TestCase as Orchestra;
use PHPUnit\Framework\Attributes\Test;

/**
 * A bad date reads as core's one message for its kind, whether it is an attribute write,
 * a date range bound or a comparison or list filter's value, with the example the
 * declared one or else the fixed one. Its source pointer or parameter says which value it
 * was. The app runs in French with one key translated. Runs on Eloquent
 * ({@see EloquentDateMessageTest}) and the in-memory witness ({@see InMemoryDateMessageTest}).
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
        $config->set('app.locale', 'fr');
    }

    protected function setUp(): void
    {
        parent::setUp();

        // One key translated, so a date filter value shows the override and every other
        // kind keeps its default.
        $app = $this->app;
        \assert($app !== null);
        $translator = $app->make('translator');
        \assert($translator instanceof Translator);
        $translator->addLines(['jsonapi-errors.FILTER_VALUE_INVALID.date.detail' => "Cette valeur n'est pas une date comme {example}."], 'fr');
    }

    protected function defineDatabaseMigrations(): void
    {
        if ($this->store() === 'eloquent') {
            DateMessageServiceProvider::migrate();
        }
    }

    private const string DATE_TIME = 'This value is not an RFC 3339 date-time with an offset, such as 2026-10-01T00:00:00Z.';

    private const string FRENCH_DATE = "Cette valeur n'est pas une date comme 2026-10-01.";

    /**
     * @return array<string, array{mixed, string, string}>
     */
    private static function badAttributes(): array
    {
        return [
            'startsAt' => ['last year', self::DATE_TIME, '/data/attributes/startsAt'],
            'endsAt' => ['2026-11-05 18:00', 'This value is not an RFC 3339 date-time with an offset, such as 2026-11-05T18:00:00+01:00.', '/data/attributes/endsAt'],
            'day' => ['2026-02-30', 'This value is not a date like 2026-10-01.', '/data/attributes/day'],
            'dueOn' => ['tomorrow', 'This value is not a date like 2026-12-24.', '/data/attributes/dueOn'],
            'opensAt' => ['09:00:00', 'This value is not a time like 09:42:00Z.', '/data/attributes/opensAt'],
            'closesAt' => ['5pm', 'This value is not a time like 17:30:00+01:00.', '/data/attributes/closesAt'],
            'breakAt' => ['12:30', 'This value is not a time like 14:30:00.', '/data/attributes/breakAt'],
            'window' => [['from' => '2026-10-01'], self::DATE_TIME, '/data/attributes/window/from'],
            'until' => ['2026-02-30', 'This value is not a date like 2026-10-01.', '/data/attributes/until'],
            'marks' => [['first' => 'soon'], 'This value is not a date like 2026-10-01.', '/data/attributes/marks/first'],
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
    public function anAttributeDateTranslatesUnderItsOwnKey(): void
    {
        $app = $this->app;
        \assert($app !== null);
        $translator = $app->make('translator');
        \assert($translator instanceof Translator);
        $translator->addLines(['jsonapi-errors.ATTRIBUTE_VALUE_INVALID.time.detail' => "Cette valeur n'est pas une heure comme {example}."], 'fr');

        $response = $this->sendJsonApiRequest('POST', '/api/dmBookings', ['data' => ['type' => 'dmBookings', 'attributes' => ['closesAt' => '5pm']]], []);

        $this->assertOneError($response, 422, 'VALIDATION_FAILED', "Cette valeur n'est pas une heure comme 17:30:00+01:00.", ['pointer' => '/data/attributes/closesAt'], 'closesAt');
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

    /**
     * @return array<string, array{string, string}>
     */
    private static function badFilters(): array
    {
        return [
            'filter[startsRange][min]=banana' => ['startsRange', self::DATE_TIME],
            'filter[startsRange][max]=2026-10-01' => ['startsRange', self::DATE_TIME],
            'filter[startsRange][min]=2026-02-30T00:00:00Z' => ['startsRange', self::DATE_TIME],
            'filter[dayRange][min]=2026-10-01T00:00:00Z' => ['dayRange', self::FRENCH_DATE],
            'filter[endsRange][min]=soon' => ['endsRange', 'This value is not an RFC 3339 date-time with an offset, such as 2026-01-01T00:00:00Z.'],
            'filter[endsRange][max]=later' => ['endsRange', 'This value is not an RFC 3339 date-time with an offset, such as 2026-12-31T23:59:59Z.'],
            'filter[startsAt]=banana' => ['startsAt', self::DATE_TIME],
            'filter[startsAfter]=2026-10-01' => ['startsAfter', self::DATE_TIME],
            'filter[days]=2026-02-30' => ['days', self::FRENCH_DATE],
            'filter[notDueOn]=tomorrow' => ['notDueOn', "Cette valeur n'est pas une date comme 2026-12-24."],
            'filter[opensAt]=09:00:00' => ['opensAt', 'This value is not a time like 09:42:00Z.'],
            'filter[breakAt]=12:30' => ['breakAt', 'This value is not a time like 14:30:00.'],
        ];
    }

    #[Test]
    public function aBadDateFilterValueIsOneErrorWithItsKindsMessage(): void
    {
        foreach (self::badFilters() as $query => [$key, $detail]) {
            $response = $this->sendJsonApiRequest('GET', '/api/dmBookings?' . $query, null, []);

            $this->assertOneError($response, 400, 'FILTER_VALUE_INVALID', $detail, ['parameter' => 'filter[' . $key . ']'], $query);
        }
    }

    #[Test]
    public function eachBadBoundOrMemberIsItsOwnError(): void
    {
        foreach (['filter[dayRange][min]=banana&filter[dayRange][max]=2026-02-30', 'filter[days]=banana,2026-10-01,2026-02-30'] as $query) {
            $response = $this->sendJsonApiRequest('GET', '/api/dmBookings?' . $query, null, []);

            self::assertSame(400, $response->getStatusCode(), (string) $response->getContent());
            self::assertSame([self::FRENCH_DATE, self::FRENCH_DATE], $response->json('errors.*.detail'), $query);
        }
    }

    #[Test]
    public function goodDateFilterValuesStillFilter(): void
    {
        foreach ([
            'filter[startsRange][min]=2026-09-30T00:00:00Z&filter[dayRange][max]=2026-10-01',
            'filter[startsAt]=2026-10-01T09:00:00Z',
            'filter[startsAfter]=2026-09-30T00:00:00Z',
            'filter[days]=2026-10-01,2026-10-02',
            'filter[breakAt]=12:30:00',
        ] as $query) {
            $response = $this->sendJsonApiRequest('GET', '/api/dmBookings?' . $query, null, []);

            self::assertSame(200, $response->getStatusCode(), $query . ': ' . $response->getContent());
        }
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
