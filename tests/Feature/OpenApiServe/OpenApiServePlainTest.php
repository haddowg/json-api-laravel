<?php

declare(strict_types=1);

namespace haddowg\JsonApiLaravel\Tests\Feature\OpenApiServe;

use haddowg\JsonApi\OpenApi\Serve\EntityTag;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\Test;

/**
 * No serve decorators (ADR 0036): the warmed bytes are served unchanged, tagged with the
 * hash the warmer stored beside them.
 *
 * @internal
 */
final class OpenApiServePlainTest extends OpenApiServeTestCase
{
    protected static function serveDecorators(): array
    {
        return ['openapi' => [], 'schemas' => []];
    }

    #[Test]
    #[Group('openapi')]
    public function the_warmed_artifact_is_served_unchanged_with_its_stored_hash(): void
    {
        $this->warm();
        $artifact = $this->store()->read('default');
        $hash = $this->store()->readHash('default');
        $this->assertIsString($artifact);
        $this->assertIsString($hash);
        $this->assertSame(EntityTag::hash($artifact), $hash);

        $response = $this->fetch('/docs.json');

        $response->assertOk()->assertHeader('Content-Type', 'application/json');
        $this->assertSame($artifact, $response->getContent());
        $this->assertSame(self::quoted($hash), $this->etagOf($response));
    }

    #[Test]
    #[Group('openapi')]
    public function an_unwarmed_document_is_tagged_with_the_hash_of_its_body(): void
    {
        $response = $this->fetch('/docs.json');
        $body = (string) $response->getContent();

        $this->assertSame(self::quoted(EntityTag::hash($body)), $this->etagOf($response));
        // Outside debug the lazy build is never written back.
        $this->assertNull($this->store()->read('default'));

        $this->warm();
        $this->assertSame($body, $this->fetch('/docs.json')->getContent());
    }

    #[Test]
    #[Group('openapi')]
    public function a_matching_if_none_match_gets_a_304_whether_strong_or_weak(): void
    {
        $this->warm();
        $etag = $this->etagOf($this->fetch('/docs.json'));

        foreach ([$etag, 'W/' . $etag, '"other", ' . $etag, '*'] as $ifNoneMatch) {
            $response = $this->fetch('/docs.json', headers: ['If-None-Match' => $ifNoneMatch]);
            $response->assertStatus(304);
            $this->assertSame('', $response->getContent(), $ifNoneMatch);
            $this->assertSame($etag, $this->etagOf($response));
            $this->assertSame(self::CACHE_CONTROL, $this->cacheControl($response));
        }

        $this->fetch('/docs.json', headers: ['If-None-Match' => '"stale"'])->assertOk();
    }

    #[Test]
    #[Group('openapi')]
    public function cache_control_comes_from_config(): void
    {
        $this->assertSame(self::CACHE_CONTROL, $this->cacheControl($this->fetch('/docs.json')));
        $this->assertSame(self::CACHE_CONTROL, $this->cacheControl($this->fetch('/schemas.json')));
    }

    #[Test]
    #[Group('openapi')]
    public function the_viewer_page_carries_the_configured_cache_control(): void
    {
        $this->assertSame(self::CACHE_CONTROL, $this->cacheControl($this->fetch('/docs')));
    }

    #[Test]
    #[Group('openapi')]
    public function no_documentation_route_sets_a_cookie_or_varies(): void
    {
        // The probe proves this app does hand out session cookies on a web route.
        $probe = $this->fetch('/session-probe')->assertOk();
        $this->assertNotSame([], $probe->baseResponse->headers->getCookies());

        foreach (['/docs.json', '/schemas.json', '/docs'] as $path) {
            $response = $this->fetch($path)->assertOk();
            $this->assertSame([], $response->baseResponse->headers->getCookies(), $path);
            $this->assertFalse($response->baseResponse->headers->has('Set-Cookie'), $path);
            $this->assertFalse($response->baseResponse->headers->has('Vary'), $path);
        }
    }

    #[Test]
    #[Group('openapi')]
    public function the_schemas_aggregate_is_served_unchanged_with_its_stored_hash(): void
    {
        $this->warm();
        $artifact = $this->store()->readSchemaAggregate('default');
        $hash = $this->store()->readSchemaAggregateHash('default');
        $this->assertIsString($artifact);
        $this->assertIsString($hash);
        $this->assertSame(EntityTag::hash($artifact), $hash);

        $response = $this->fetch('/schemas.json');
        $this->assertSame($artifact, $response->getContent());
        $this->assertSame(self::quoted($hash), $this->etagOf($response));

        $this->fetch('/schemas.json', headers: ['If-None-Match' => 'W/' . self::quoted($hash)])->assertStatus(304);
    }
}
