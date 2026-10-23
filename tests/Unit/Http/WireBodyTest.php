<?php

declare(strict_types=1);

namespace haddowg\JsonApiLaravel\Tests\Unit\Http;

use haddowg\JsonApiLaravel\Http\WireBody;
use Illuminate\Http\Request;
use Nyholm\Psr7\Factory\Psr17Factory;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Psr\Http\Message\ServerRequestInterface;
use Symfony\Bridge\PsrHttpMessage\Factory\PsrHttpFactory;
use Symfony\Component\HttpFoundation\Request as SymfonyRequest;

/**
 * @internal
 */
#[CoversClass(WireBody::class)]
final class WireBodyTest extends TestCase
{
    /**
     * @return iterable<string, array{string}>
     */
    public static function jsonApiMediaTypes(): iterable
    {
        yield 'JSON:API' => ['application/vnd.api+json'];
        yield 'atomic extension' => ['application/vnd.api+json; ext="https://jsonapi.org/ext/atomic"'];
    }

    #[DataProvider('jsonApiMediaTypes')]
    public function test_it_decodes_the_raw_content_not_the_rewritten_bag(string $contentType): void
    {
        $request = self::request($contentType, '{"data":{"attributes":{"title":"  Spaced  ","status":""}}}');
        // What TrimStrings + ConvertEmptyStringsToNull leave in the bag.
        $request->json()->replace(['data' => ['attributes' => ['title' => 'Spaced', 'status' => null]]]);

        $psrRequest = WireBody::apply(self::psr($request), $request);

        self::assertSame(['data' => ['attributes' => ['title' => '  Spaced  ', 'status' => '']]], $psrRequest->getParsedBody());
    }

    public function test_it_leaves_malformed_json_for_core_to_reject(): void
    {
        $request = self::request('application/vnd.api+json', '{"data": ');

        self::assertSame([], WireBody::apply(self::psr($request), $request)->getParsedBody());
    }

    public function test_it_leaves_a_non_json_body_alone(): void
    {
        $request = self::request('text/plain', '  padded  ');
        $psrRequest = self::psr($request);

        self::assertSame($psrRequest, WireBody::apply($psrRequest, $request));
        self::assertSame('  padded  ', (string) $psrRequest->getBody());
    }

    private static function request(string $contentType, string $content): Request
    {
        // As Laravel captures it: a JSON body's input bag is the decoded JSON bag.
        return Request::createFromBase(SymfonyRequest::create('/api/albums', 'POST', server: ['CONTENT_TYPE' => $contentType], content: $content));
    }

    private static function psr(Request $request): ServerRequestInterface
    {
        $factory = new Psr17Factory();

        return (new PsrHttpFactory($factory, $factory, $factory, $factory))->createRequest($request);
    }
}
