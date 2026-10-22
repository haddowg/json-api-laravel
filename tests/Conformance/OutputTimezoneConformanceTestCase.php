<?php

declare(strict_types=1);

namespace haddowg\JsonApiLaravel\Tests\Conformance;

use haddowg\JsonApiLaravel\JsonApiServiceProvider;
use haddowg\JsonApiLaravel\Testing\InteractsWithJsonApi;
use Orchestra\Testbench\Attributes\DefineEnvironment;
use Orchestra\Testbench\TestCase as Orchestra;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\Test;

/**
 * The server's `output_timezone` against both data layers. Every stored value carries
 * Europe/Paris. The `default` server converts to UTC through the global key, and the
 * `admin` server sets its own America/New_York. `DateTime` attributes convert, in primary
 * data and in `included`; `Date` and `Time` keep their local value; a field with its own
 * `outputTimezone()` keeps it.
 */
abstract class OutputTimezoneConformanceTestCase extends Orchestra
{
    use InteractsWithJsonApi;

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

    private ?string $globalZone = 'UTC';

    private ?string $adminZone = 'America/New_York';

    /**
     * @param \Illuminate\Foundation\Application $app
     */
    protected function defineEnvironment($app): void
    {
        /** @var \Illuminate\Contracts\Config\Repository $config */
        $config = $app['config'];
        $config->set('jsonapi.output_timezone', $this->globalZone);
        $config->set('jsonapi.servers', [
            'default' => ['prefix' => 'api', 'middleware' => [], 'domain' => null],
            'admin' => ['prefix' => 'admin', 'middleware' => [], 'domain' => null, 'output_timezone' => $this->adminZone],
        ]);
    }

    /**
     * Runs before {@see defineEnvironment()}, so it only picks the zones.
     *
     * @param \Illuminate\Foundation\Application $app
     */
    protected function withoutOutputTimezone($app): void
    {
        $this->globalZone = null;
        $this->adminZone = null;
    }

    /**
     * @param \Illuminate\Foundation\Application $app
     */
    protected function adminOptsOut($app): void
    {
        $this->adminZone = '';
    }

    protected function seedConformanceData(): void {}

    protected function setUp(): void
    {
        parent::setUp();
        $this->seedConformanceData();
    }

    #[Test]
    #[Group('output-timezone')]
    public function dateTimeAttributesRenderInTheServerZone(): void
    {
        $response = $this->jsonApi()->withInclude('venue')->get('/api/gigs/1');

        $response->assertOk();
        $response->assertJsonPath('data.attributes.startsAt', '2024-06-01T18:00:00+00:00');
        $response->assertJsonPath('included.0.type', 'venues');
        $response->assertJsonPath('included.0.attributes.openedAt', '2020-01-15T09:00:00+00:00');
    }

    #[Test]
    #[Group('output-timezone')]
    public function dateAndTimeAttributesKeepTheirLocalValue(): void
    {
        $response = $this->jsonApi()->get('/api/gigs/1');

        $response->assertOk();
        // Converted to UTC, these would read 2024-05-31 and 17:30:00.
        $response->assertJsonPath('data.attributes.day', '2024-06-01');
        $response->assertJsonPath('data.attributes.doorsOpen', '19:30:00');
    }

    #[Test]
    #[Group('output-timezone')]
    public function aFieldsOwnZoneBeatsTheServerZone(): void
    {
        $this->jsonApi()->get('/api/gigs/1')
            ->assertOk()
            ->assertJsonPath('data.attributes.startsAtInTokyo', '2024-06-02T03:00:00+09:00');

        $this->jsonApi()->get('/admin/gigs/1')
            ->assertOk()
            ->assertJsonPath('data.attributes.startsAtInTokyo', '2024-06-02T03:00:00+09:00');
    }

    #[Test]
    #[Group('output-timezone')]
    public function aNamedServerRendersInItsOwnZone(): void
    {
        $response = $this->jsonApi()->withInclude('venue')->get('/admin/gigs');

        $response->assertOk();
        $response->assertJsonPath('data.0.attributes.startsAt', '2024-06-01T14:00:00-04:00');
        $response->assertJsonPath('data.0.attributes.day', '2024-06-01');
        $response->assertJsonPath('data.0.attributes.doorsOpen', '19:30:00');
        $response->assertJsonPath('included.0.attributes.openedAt', '2020-01-15T04:00:00-05:00');
    }

    #[Test]
    #[Group('output-timezone')]
    public function theRelatedEndpointRendersInTheServerZone(): void
    {
        $this->jsonApi()->get('/api/gigs/1/venue')
            ->assertOk()
            ->assertJsonPath('data.attributes.openedAt', '2020-01-15T09:00:00+00:00');
    }

    #[Test]
    #[Group('output-timezone')]
    #[DefineEnvironment('withoutOutputTimezone')]
    public function noOutputTimezoneLeavesEveryValueInItsOwnZone(): void
    {
        foreach (['/api', '/admin'] as $prefix) {
            $response = $this->jsonApi()->withInclude('venue')->get($prefix . '/gigs/1');

            $response->assertOk();
            $response->assertJsonPath('data.attributes.startsAt', '2024-06-01T20:00:00+02:00');
            $response->assertJsonPath('data.attributes.startsAtInTokyo', '2024-06-02T03:00:00+09:00');
            $response->assertJsonPath('data.attributes.day', '2024-06-01');
            $response->assertJsonPath('data.attributes.doorsOpen', '19:30:00');
            $response->assertJsonPath('included.0.attributes.openedAt', '2020-01-15T10:00:00+01:00');
        }
    }

    #[Test]
    #[Group('output-timezone')]
    #[DefineEnvironment('adminOptsOut')]
    public function anEmptyServerZoneTurnsTheGlobalZoneOff(): void
    {
        $this->jsonApi()->get('/admin/gigs/1')
            ->assertOk()
            ->assertJsonPath('data.attributes.startsAt', '2024-06-01T20:00:00+02:00');

        $this->jsonApi()->get('/api/gigs/1')
            ->assertOk()
            ->assertJsonPath('data.attributes.startsAt', '2024-06-01T18:00:00+00:00');
    }
}
