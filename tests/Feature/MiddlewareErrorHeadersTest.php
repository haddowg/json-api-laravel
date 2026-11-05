<?php

declare(strict_types=1);

namespace haddowg\JsonApiLaravel\Tests\Feature;

use Orchestra\Testbench\Attributes\DefineEnvironment;
use PHPUnit\Framework\Attributes\CoversNothing;
use PHPUnit\Framework\Attributes\Test;

/**
 * Laravel's `auth.basic` and `throttle` middleware in front of the package's own routes.
 * Their refusals render as JSON:API error documents and keep the headers the middleware
 * set: the `WWW-Authenticate` challenge on a `401` and `Retry-After` on a `429`.
 *
 * @internal
 */
#[CoversNothing]
final class MiddlewareErrorHeadersTest extends TestCase
{
    /**
     * @param \Illuminate\Foundation\Application $app
     */
    protected function behindBasicAuth($app): void
    {
        $this->serverMiddleware($app, ['auth.basic']);
    }

    /**
     * @param \Illuminate\Foundation\Application $app
     */
    protected function behindThrottle($app): void
    {
        $this->serverMiddleware($app, ['throttle:1,1']);
    }

    #[Test]
    #[DefineEnvironment('behindBasicAuth')]
    public function anAnonymousRequestGetsAJsonApi401WithTheBasicChallenge(): void
    {
        $response = $this->get('/api/artists', ['Accept' => self::MEDIA_TYPE]);

        $response->assertStatus(401);
        $response->assertHeader('Content-Type', self::MEDIA_TYPE);
        $response->assertHeader('WWW-Authenticate', 'Basic');
        $response->assertJsonPath('errors.0.status', '401');
        $response->assertJsonPath('errors.0.code', 'AUTHENTICATION_REQUIRED');
    }

    #[Test]
    #[DefineEnvironment('behindThrottle')]
    public function aRequestPastTheLimitGetsAJsonApi429WithRetryAfter(): void
    {
        $this->get('/api/artists', ['Accept' => self::MEDIA_TYPE])->assertOk();

        $response = $this->get('/api/artists', ['Accept' => self::MEDIA_TYPE]);

        $response->assertStatus(429);
        $response->assertHeader('Content-Type', self::MEDIA_TYPE);
        self::assertIsNumeric($response->headers->get('Retry-After'));
        $response->assertHeader('X-RateLimit-Limit', '1');
        $response->assertHeader('X-RateLimit-Remaining', '0');
        $response->assertJsonCount(1, 'errors');
        $response->assertJsonPath('errors.0.status', '429');
        $response->assertJsonPath('errors.0.title', 'Too Many Requests');
    }

    /**
     * @param \Illuminate\Foundation\Application $app
     * @param list<string>                       $middleware
     */
    private function serverMiddleware($app, array $middleware): void
    {
        /** @var \Illuminate\Contracts\Config\Repository $config */
        $config = $app['config'];
        $config->set('jsonapi.servers.default.middleware', $middleware);
    }
}
