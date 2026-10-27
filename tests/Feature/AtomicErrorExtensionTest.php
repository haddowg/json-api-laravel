<?php

declare(strict_types=1);

namespace haddowg\JsonApiLaravel\Tests\Feature;

use haddowg\JsonApi\Atomic\AtomicExtension;
use haddowg\JsonApi\Exception\MediaTypeUnacceptable;
use haddowg\JsonApi\Exception\MediaTypeUnsupported;
use haddowg\JsonApi\Exception\QueryParamUnrecognized;
use haddowg\JsonApiLaravel\Http\JsonApiController;
use haddowg\JsonApiLaravel\Routing\JsonApiRoute;
use PHPUnit\Framework\Attributes\CoversNothing;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\Test;

/**
 * An error on the atomic route declares the atomic ext only when the request named it,
 * whatever raised the error, since the renderer reads the headers rather than relying on
 * negotiation having run. A `415` or `406` never declares it.
 *
 * @internal
 */
#[CoversNothing]
final class AtomicErrorExtensionTest extends TestCase
{
    private const string ATOMIC = 'application/vnd.api+json; ext="' . AtomicExtension::URI . '"';

    /**
     * @param \Illuminate\Routing\Router $router
     */
    protected function defineRoutes($router): void
    {
        $throwing = [
            'bad-request' => static fn() => throw new QueryParamUnrecognized('bogus'),
            'unsupported' => static fn() => throw new MediaTypeUnsupported(self::ATOMIC),
            'unacceptable' => static fn() => throw new MediaTypeUnacceptable(self::ATOMIC),
        ];

        foreach ($throwing as $path => $action) {
            $router->post('/api/atomic-probe/' . $path, $action)
                ->defaults(JsonApiRoute::MARKER, true)
                ->defaults(JsonApiController::ATOMIC_ATTRIBUTE, true);
        }
    }

    /**
     * @return iterable<string, array{array<string, string>, string}>
     */
    public static function requestHeaders(): iterable
    {
        yield 'no ext named' => [['HTTP_ACCEPT' => self::MEDIA_TYPE, 'CONTENT_TYPE' => self::MEDIA_TYPE], self::MEDIA_TYPE];
        yield 'ext on Content-Type' => [['HTTP_ACCEPT' => self::MEDIA_TYPE, 'CONTENT_TYPE' => self::ATOMIC], self::ATOMIC];
        yield 'ext on Accept' => [['HTTP_ACCEPT' => self::ATOMIC, 'CONTENT_TYPE' => self::MEDIA_TYPE], self::ATOMIC];
        yield 'ext on a later Accept range' => [['HTTP_ACCEPT' => 'text/html, ' . self::ATOMIC . ';q=0.9'], self::ATOMIC];
        yield 'another ext only' => [['HTTP_ACCEPT' => 'application/vnd.api+json; ext="https://example.com/ext/other"'], self::MEDIA_TYPE];
    }

    /**
     * @param array<string, string> $server
     */
    #[Test]
    #[Group('spec:atomic')]
    #[DataProvider('requestHeaders')]
    public function aValidationErrorDeclaresTheExtOnlyWhenTheRequestNamedIt(array $server, string $contentType): void
    {
        $this->call('POST', '/api/atomic-probe/bad-request', [], [], [], $server, '{}')
            ->assertStatus(400)
            ->assertHeader('Content-Type', $contentType);
    }

    #[Test]
    #[Group('spec:atomic')]
    public function aNegotiationFailureNeverDeclaresTheExt(): void
    {
        $server = ['HTTP_ACCEPT' => self::ATOMIC, 'CONTENT_TYPE' => self::ATOMIC];

        $this->call('POST', '/api/atomic-probe/unsupported', [], [], [], $server, '{}')
            ->assertStatus(415)
            ->assertHeader('Content-Type', self::MEDIA_TYPE);

        $this->call('POST', '/api/atomic-probe/unacceptable', [], [], [], $server, '{}')
            ->assertStatus(406)
            ->assertHeader('Content-Type', self::MEDIA_TYPE);
    }
}
