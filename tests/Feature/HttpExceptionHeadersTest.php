<?php

declare(strict_types=1);

namespace haddowg\JsonApiLaravel\Tests\Feature;

use haddowg\JsonApi\Response\ErrorResponse;
use haddowg\JsonApi\Schema\Error\Error;
use haddowg\JsonApiLaravel\Exception\ExceptionMapperInterface;
use haddowg\JsonApiLaravel\Routing\JsonApiRoute;
use PHPUnit\Framework\Attributes\CoversNothing;
use PHPUnit\Framework\Attributes\Test;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Symfony\Component\HttpKernel\Exception\TooManyRequestsHttpException;
use Symfony\Component\HttpKernel\Exception\UnauthorizedHttpException;

/**
 * An HTTP exception thrown on a JSON:API route keeps its own headers on the JSON:API
 * error response, whichever arm renders it, while the status, the body, `Content-Type`
 * and `Content-Length` come from the JSON:API rendering.
 *
 * @internal
 */
#[CoversNothing]
final class HttpExceptionHeadersTest extends TestCase
{
    /**
     * @param \Illuminate\Foundation\Application $app
     */
    protected function defineEnvironment($app): void
    {
        $app->instance('test.teapot_mapper', new class implements ExceptionMapperInterface {
            public function map(\Throwable $throwable): ?ErrorResponse
            {
                if (!$throwable instanceof HttpException || $throwable->getStatusCode() !== 418) {
                    return null;
                }

                return ErrorResponse::fromErrors(new Error(status: '418', code: 'TEAPOT', title: 'Teapot'));
            }
        });
        $app->tag('test.teapot_mapper', 'jsonapi.exception_mapper');
    }

    /**
     * @param \Illuminate\Routing\Router $router
     */
    protected function defineRoutes($router): void
    {
        $throwing = [
            'unauthorized' => static fn() => throw new UnauthorizedHttpException('Basic realm="api"'),
            'too-many' => static fn() => throw new TooManyRequestsHttpException(30, headers: ['X-RateLimit-Limit' => '60', 'X-RateLimit-Remaining' => '0']),
            'not-found' => static fn() => throw new HttpException(404, headers: ['Cache-Control' => 'max-age=60, public', 'Content-Type' => 'text/html', 'Content-Length' => '9999']),
            'teapot' => static fn() => throw new HttpException(418, headers: ['X-Brew' => 'earl-grey']),
        ];

        foreach ($throwing as $path => $action) {
            $router->get('/api/probe/' . $path, $action)->defaults(JsonApiRoute::MARKER, true);
        }
    }

    #[Test]
    public function aChallengeStaysOnA401(): void
    {
        $response = $this->get('/api/probe/unauthorized', ['Accept' => self::MEDIA_TYPE]);

        $response->assertStatus(401);
        $response->assertHeader('Content-Type', self::MEDIA_TYPE);
        $response->assertHeader('WWW-Authenticate', 'Basic realm="api"');
        $response->assertJsonPath('errors.0.code', 'AUTHENTICATION_REQUIRED');
    }

    #[Test]
    public function rateLimitHeadersStayOnA429(): void
    {
        $response = $this->get('/api/probe/too-many', ['Accept' => self::MEDIA_TYPE]);

        $response->assertStatus(429);
        $response->assertHeader('Content-Type', self::MEDIA_TYPE);
        $response->assertHeader('Retry-After', '30');
        $response->assertHeader('X-RateLimit-Limit', '60');
        $response->assertHeader('X-RateLimit-Remaining', '0');
        $response->assertJsonCount(1, 'errors');
        $response->assertJsonPath('errors.0.status', '429');
        $response->assertJsonPath('errors.0.title', 'Too Many Requests');
    }

    #[Test]
    public function theJsonApiRenderingKeepsItsContentHeaders(): void
    {
        $response = $this->get('/api/probe/not-found', ['Accept' => self::MEDIA_TYPE]);

        $response->assertStatus(404);
        $response->assertHeader('Content-Type', self::MEDIA_TYPE);
        $response->assertHeader('Cache-Control', 'max-age=60, public');
        self::assertNotSame('9999', $response->headers->get('Content-Length'));
        self::assertSame([['status' => '404', 'title' => 'Not Found']], $response->json('errors'));
    }

    #[Test]
    public function aMappedHttpExceptionKeepsItsHeaders(): void
    {
        $response = $this->get('/api/probe/teapot', ['Accept' => self::MEDIA_TYPE]);

        $response->assertStatus(418);
        $response->assertHeader('X-Brew', 'earl-grey');
        $response->assertJsonPath('errors.0.code', 'TEAPOT');
    }
}
