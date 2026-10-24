<?php

declare(strict_types=1);

namespace haddowg\JsonApiLaravel\Tests\Feature\OpenApiServe;

use haddowg\JsonApi\OpenApi\Serve\EntityTag;
use haddowg\JsonApiLaravel\Tests\Fixtures\OpenApiServe\FeatureGateDecorator;
use haddowg\JsonApiLaravel\Tests\Fixtures\OpenApiServe\FeatureGateSchemaDecorator;
use haddowg\JsonApiLaravel\Tests\Fixtures\OpenApiServe\HostServersDecorator;
use haddowg\JsonApiLaravel\Tests\Fixtures\OpenApiServe\UnkeyedDecorator;
use haddowg\JsonApiLaravel\Tests\Fixtures\OpenApiServe\UnkeyedSchemaDecorator;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\Test;

/**
 * One serve decorator without a cache key (ADR 0036): the ETag falls back to the hash of
 * the final body, so a conditional request still decorates before it can answer 304.
 *
 * @internal
 */
final class OpenApiServeUnkeyedTest extends OpenApiServeTestCase
{
    protected static function serveDecorators(): array
    {
        return [
            'openapi' => [HostServersDecorator::class, FeatureGateDecorator::class, UnkeyedDecorator::class],
            'schemas' => [FeatureGateSchemaDecorator::class, UnkeyedSchemaDecorator::class],
        ];
    }

    #[Test]
    #[Group('openapi')]
    public function the_etag_is_the_hash_of_the_body(): void
    {
        $response = $this->fetch('/docs.json', 'one.test');
        $this->assertValidOpenApi($response);

        $this->assertSame(self::quoted(EntityTag::hash((string) $response->getContent())), $this->etagOf($response));
        $this->assertSame('Served to one.test', $this->arr($this->document($response)['info'] ?? null)['description'] ?? null);
        $this->assertSame(0, FeatureGateDecorator::$keyed);
    }

    #[Test]
    #[Group('openapi')]
    public function a_matching_if_none_match_still_gets_a_304_after_decorating(): void
    {
        $etag = $this->etagOf($this->fetch('/docs.json', 'one.test'));

        $response = $this->fetch('/docs.json', 'one.test', ['If-None-Match' => 'W/' . $etag]);
        $response->assertStatus(304);
        $this->assertSame('', $response->getContent());
        $this->assertSame(2, UnkeyedDecorator::$decorated);
    }

    #[Test]
    #[Group('openapi')]
    public function the_schemas_aggregate_falls_back_to_the_body_hash_too(): void
    {
        $response = $this->fetch('/schemas.json', 'one.test');
        $this->assertSame(self::quoted(EntityTag::hash((string) $response->getContent())), $this->etagOf($response));
        $this->assertSame('one.test', $this->arr($this->document($response)['albums'] ?? null)['x-served-to'] ?? null);

        $this->fetch('/schemas.json', 'one.test', ['If-None-Match' => $this->etagOf($response)])->assertStatus(304);
        $this->assertSame(2, UnkeyedSchemaDecorator::$decorated);
    }
}
