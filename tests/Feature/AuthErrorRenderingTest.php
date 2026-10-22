<?php

declare(strict_types=1);

namespace haddowg\JsonApiLaravel\Tests\Feature;

use haddowg\JsonApiLaravel\Routing\JsonApiRoute;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Auth\AuthenticationException;
use Orchestra\Testbench\Attributes\DefineEnvironment;
use PHPUnit\Framework\Attributes\CoversNothing;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;

/**
 * A 401 or 403 on a JSON:API route renders through core's `AuthenticationRequired` /
 * `AccessDenied`, so it carries a stable code, whether it starts as Laravel's auth
 * exception or as an HTTP exception with that status. The detail stays debug-only.
 *
 * @internal
 */
#[CoversNothing]
final class AuthErrorRenderingTest extends TestCase
{
    /**
     * @param \Illuminate\Routing\Router $router
     */
    protected function defineRoutes($router): void
    {
        $throwing = [
            'authentication' => static fn() => throw new AuthenticationException('Who are you?'),
            'authorization' => static fn() => throw new AuthorizationException('Not yours.'),
            'abort-401' => static fn() => \abort(401, 'Log in first.'),
            'abort-403' => static fn() => \abort(403, 'Keep out.'),
            'abort-404' => static fn() => \abort(404, 'Nothing here.'),
        ];

        foreach ($throwing as $path => $action) {
            $router->get('/api/probe/' . $path, $action)->defaults(JsonApiRoute::MARKER, true);
        }
    }

    /**
     * @param \Illuminate\Foundation\Application $app
     */
    protected function debugOn($app): void
    {
        /** @var \Illuminate\Contracts\Config\Repository $config */
        $config = $app['config'];
        $config->set('app.debug', true);
    }

    /**
     * @return iterable<string, array{string, int, string, string, string}>
     */
    public static function authErrors(): iterable
    {
        yield 'AuthenticationException' => ['authentication', 401, 'AUTHENTICATION_REQUIRED', 'Unauthorized', 'Who are you?'];
        yield 'AuthorizationException' => ['authorization', 403, 'ACCESS_DENIED', 'Forbidden', 'Not yours.'];
        yield 'HTTP 401' => ['abort-401', 401, 'AUTHENTICATION_REQUIRED', 'Unauthorized', 'Log in first.'];
        yield 'HTTP 403' => ['abort-403', 403, 'ACCESS_DENIED', 'Forbidden', 'Keep out.'];
    }

    #[Test]
    #[DataProvider('authErrors')]
    public function anAuthErrorCarriesItsCodeAndNoDetail(string $path, int $status, string $code, string $title, string $debugDetail): void
    {
        $this->config(false);

        $response = $this->get('/api/probe/' . $path, ['Accept' => self::MEDIA_TYPE]);

        $response->assertStatus($status);
        self::assertSame(
            [['status' => (string) $status, 'code' => $code, 'title' => $title]],
            $response->json('errors'),
        );
        self::assertStringNotContainsString($debugDetail, (string) $response->getContent());
    }

    #[Test]
    #[DataProvider('authErrors')]
    #[DefineEnvironment('debugOn')]
    public function anAuthErrorShowsItsMessageAsTheDetailInDebug(string $path, int $status, string $code, string $title, string $detail): void
    {
        $response = $this->get('/api/probe/' . $path, ['Accept' => self::MEDIA_TYPE]);

        $response->assertStatus($status);
        $response->assertJsonPath('errors.0.code', $code);
        $response->assertJsonPath('errors.0.title', $title);
        $response->assertJsonPath('errors.0.detail', $detail);
    }

    #[Test]
    public function anyOtherHttpStatusKeepsItsCodelessError(): void
    {
        $this->config(false);

        $response = $this->get('/api/probe/abort-404', ['Accept' => self::MEDIA_TYPE]);

        $response->assertStatus(404);
        self::assertSame([['status' => '404', 'title' => 'Not Found']], $response->json('errors'));
    }

    private function config(bool $debug): void
    {
        /** @var \Illuminate\Contracts\Config\Repository $config */
        $config = $this->app?->make('config');
        $config->set('app.debug', $debug);
    }
}
