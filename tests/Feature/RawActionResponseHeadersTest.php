<?php

declare(strict_types=1);

namespace haddowg\JsonApiLaravel\Tests\Feature;

use haddowg\JsonApiLaravel\Event\AfterActionEvent;
use haddowg\JsonApiLaravel\Event\BeforeActionEvent;
use haddowg\JsonApiLaravel\JsonApiServiceProvider;
use haddowg\JsonApiLaravel\Tests\Fixtures\Lifecycle\LifecycleServiceProvider;
use haddowg\JsonApiLaravel\Tests\Fixtures\RawResponses\RelicActionsServiceProvider;
use Illuminate\Support\Facades\Event;
use Orchestra\Testbench\TestCase as Orchestra;
use PHPUnit\Framework\Attributes\CoversNothing;
use PHPUnit\Framework\Attributes\Test;

/**
 * A raw action response passes through the response-headers middleware and the action
 * lifecycle events like a JSON:API one: `relics` declares deprecation headers.
 *
 * @internal
 */
#[CoversNothing]
final class RawActionResponseHeadersTest extends Orchestra
{
    /**
     * @param \Illuminate\Foundation\Application $app
     *
     * @return array<int, class-string>
     */
    protected function getPackageProviders($app): array
    {
        return [JsonApiServiceProvider::class, LifecycleServiceProvider::class, RelicActionsServiceProvider::class];
    }

    #[Test]
    public function theTypesDeprecationHeadersReachARawResponse(): void
    {
        $response = $this->get('/api/relics/1/-actions/card', ['Accept' => 'text/plain']);

        $response->assertOk();
        self::assertSame('relic', $response->getContent());
        self::assertSame('true', $response->headers->get('Deprecation'));
        self::assertSame('Wed, 11 Nov 2026 00:00:00 GMT', $response->headers->get('Sunset'));
    }

    #[Test]
    public function theActionEventsFireAroundARawResponse(): void
    {
        Event::fake([BeforeActionEvent::class, AfterActionEvent::class]);

        $this->get('/api/relics/1/-actions/card', ['Accept' => 'text/plain'])->assertOk();

        Event::assertDispatched(BeforeActionEvent::class);
        Event::assertDispatched(AfterActionEvent::class);
    }
}
