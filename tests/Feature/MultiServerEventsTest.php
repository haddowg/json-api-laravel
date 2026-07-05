<?php

declare(strict_types=1);

namespace haddowg\JsonApiLaravel\Tests\Feature;

use haddowg\JsonApiLaravel\Event\AfterFetchOneEvent;
use haddowg\JsonApiLaravel\Event\ServingEvent;
use haddowg\JsonApiLaravel\JsonApiServiceProvider;
use haddowg\JsonApiLaravel\Tests\Fixtures\Lifecycle\LifecycleServiceProvider;
use Illuminate\Support\Facades\Event;
use Illuminate\Testing\TestResponse;
use Orchestra\Testbench\TestCase as Orchestra;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\Test;

/**
 * Multi-server lifecycle events (PLAN §7): the `serverName` a lifecycle event carries is
 * the server the request dispatched on, so a listener resolves the right server in a
 * multi-server app. The `gizmos` fixture is exposed on both the `default` (prefix `api`)
 * and `admin` (prefix `admin-api`) servers; a read on each server's prefix dispatches the
 * same event with the matching `serverName`, and the serving bridge fires once per server
 * dispatch with the same name.
 *
 * @internal
 */
final class MultiServerEventsTest extends Orchestra
{
    public const string MEDIA_TYPE = 'application/vnd.api+json';

    /**
     * @param \Illuminate\Foundation\Application $app
     *
     * @return array<int, class-string>
     */
    protected function getPackageProviders($app): array
    {
        return [
            JsonApiServiceProvider::class,
            LifecycleServiceProvider::class,
        ];
    }

    /**
     * @param \Illuminate\Foundation\Application $app
     */
    protected function defineEnvironment($app): void
    {
        /** @var \Illuminate\Contracts\Config\Repository $config */
        $config = $app['config'];
        $config->set('jsonapi.servers', [
            'default' => ['prefix' => 'api', 'middleware' => [], 'domain' => null],
            'admin' => ['prefix' => 'admin-api', 'middleware' => [], 'domain' => null],
        ]);
    }

    #[Test]
    #[Group('events')]
    public function anEventOnTheDefaultServerCarriesTheDefaultServerName(): void
    {
        Event::fake([AfterFetchOneEvent::class, ServingEvent::class]);

        $this->readJsonApi('/api/gizmos/1')->assertOk();

        Event::assertDispatched(AfterFetchOneEvent::class, static fn(AfterFetchOneEvent $e): bool => $e->type === 'gizmos' && $e->serverName === 'default');
        Event::assertDispatched(ServingEvent::class, static fn(ServingEvent $e): bool => $e->serverName === 'default');
    }

    #[Test]
    #[Group('events')]
    public function anEventOnTheAdminServerCarriesTheAdminServerName(): void
    {
        Event::fake([AfterFetchOneEvent::class, ServingEvent::class]);

        $this->readJsonApi('/admin-api/gizmos/1')->assertOk();

        Event::assertDispatched(AfterFetchOneEvent::class, static fn(AfterFetchOneEvent $e): bool => $e->type === 'gizmos' && $e->serverName === 'admin');
        Event::assertDispatched(ServingEvent::class, static fn(ServingEvent $e): bool => $e->serverName === 'admin');
    }

    /**
     * @return TestResponse<\Symfony\Component\HttpFoundation\Response>
     */
    private function readJsonApi(string $uri): TestResponse
    {
        return $this->get($uri, ['Accept' => self::MEDIA_TYPE]);
    }
}
