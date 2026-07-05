<?php

declare(strict_types=1);

namespace haddowg\JsonApiLaravel\Tests\Feature;

use haddowg\JsonApiLaravel\Testing\InteractsWithJsonApi;
use haddowg\JsonApiLaravel\Testing\SchemaConformanceTrait;
use haddowg\JsonApiLaravel\Testing\SchemaDocumentKind;
use PHPUnit\Framework\Attributes\CoversNothing;
use PHPUnit\Framework\Attributes\Test;

/**
 * The round-trip conformance guarantee ported from the bundle (PLAN decision 12):
 * real workbench responses validate against the OpenAPI component schemas the package's
 * {@see \haddowg\JsonApiLaravel\OpenApi\DocumentFactory} generates — proving the projected
 * document actually describes the responses served (the byte-compatible contract has
 * teeth in a test suite, blueprint §6). Uses the {@see SchemaConformanceTrait} together
 * with {@see InteractsWithJsonApi} over the in-memory workbench.
 *
 * Validation runs over `opis/json-schema`; the trait skips (never fails) when it is
 * absent, so this suite is a no-op rather than a failure without opis installed.
 *
 * @internal
 */
#[CoversNothing]
final class TestingKitSchemaConformanceTest extends TestCase
{
    use InteractsWithJsonApi;
    use SchemaConformanceTrait;

    #[Test]
    public function aCollectionResponseValidatesAgainstTheGeneratedCollectionSchema(): void
    {
        $response = $this->jsonApi()->get('/api/albums')->assertOk();

        $this->assertResponseMatchesGeneratedSchema($response, 'albums', SchemaDocumentKind::Collection);
    }

    #[Test]
    public function aSingleResourceResponseValidatesAgainstTheGeneratedDocumentSchema(): void
    {
        $response = $this->jsonApi()->get('/api/albums/1')->assertOk();

        $this->assertResponseMatchesGeneratedSchema($response, 'albums', SchemaDocumentKind::Single);
    }

    #[Test]
    public function aReadOnlyTypeAlsoRoundTrips(): void
    {
        $this->assertResponseMatchesGeneratedSchema(
            $this->jsonApi()->get('/api/artists')->assertOk(),
            'artists',
            SchemaDocumentKind::Collection,
        );
        $this->assertResponseMatchesGeneratedSchema(
            $this->jsonApi()->get('/api/artists/1')->assertOk(),
            'artists',
            SchemaDocumentKind::Single,
        );
    }

    #[Test]
    public function aRelationshipEndpointResponseValidatesAgainstTheGeneratedRelationshipSchema(): void
    {
        $this->assertResponseMatchesGeneratedSchema(
            $this->jsonApi()->get('/api/albums/1/relationships/artist')->assertOk(),
            'albums',
            SchemaDocumentKind::Relationship,
            'artist',
        );

        $this->assertResponseMatchesGeneratedSchema(
            $this->jsonApi()->get('/api/albums/1/artist')->assertOk(),
            'albums',
            SchemaDocumentKind::Related,
            'artist',
        );
    }

    #[Test]
    public function theConformanceCheckHasTeeth(): void
    {
        // A collection body (primary `data` is a list) must NOT validate against the
        // single-resource `AlbumsDocument` component (primary `data` is one object) — the
        // boolean seam proving the guarantee is not vacuously true.
        $collectionBody = (string) $this->jsonApi()->get('/api/albums')->baseResponse->getContent();

        self::assertFalse(
            $this->bodyMatchesGeneratedComponent($collectionBody, 'AlbumsDocument'),
            'A collection body must not validate against the single-resource document component.',
        );
    }
}
