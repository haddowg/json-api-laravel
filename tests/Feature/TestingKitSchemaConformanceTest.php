<?php

declare(strict_types=1);

namespace haddowg\JsonApiLaravel\Tests\Feature;

use haddowg\JsonApiLaravel\Testing\InteractsWithJsonApi;
use haddowg\JsonApiLaravel\Testing\SchemaConformanceTrait;
use haddowg\JsonApiLaravel\Testing\SchemaDocumentKind;
use Illuminate\Support\Arr;
use Illuminate\Testing\TestResponse;
use PHPUnit\Framework\AssertionFailedError;
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

    #[Test]
    public function aFailureMessageNamesTheTypesInvolved(): void
    {
        $collectionBody = (string) $this->jsonApi()->get('/api/albums')->baseResponse->getContent();

        try {
            $this->assertBodyMatchesGeneratedComponent($collectionBody, 'AlbumsDocument');
        } catch (AssertionFailedError $e) {
            $message = $e->getMessage();
            self::assertStringContainsString('/data: The data (array) must match the type: object', $message);
            self::assertStringNotContainsString('{type}', $message);
            self::assertStringNotContainsString('{expected}', $message);

            return;
        }

        self::fail('A collection body must not validate against the single-resource document component.');
    }

    #[Test]
    public function aSparseFieldsetResponseValidatesAgainstTheGeneratedSchema(): void
    {
        $this->assertResponseMatchesGeneratedSchema(
            $this->jsonApi()->withFields('albums', 'title')->get('/api/albums/1')->assertOk(),
            'albums',
            SchemaDocumentKind::Single,
        );
        $this->assertResponseMatchesGeneratedSchema(
            $this->jsonApi()->withFields('albums', 'title')->get('/api/albums')->assertOk(),
            'albums',
            SchemaDocumentKind::Collection,
        );
    }

    #[Test]
    public function aMemberInsideTheFieldsetIsStillRequired(): void
    {
        $body = self::without($this->jsonApi()->withFields('albums', 'title', 'status')->get('/api/albums/1')->assertOk(), 'data.attributes.status');

        self::assertFalse(
            $this->bodyMatchesGeneratedComponent($body, 'AlbumsDocument', fieldsets: ['albums' => 'title,status']),
            'A member the fieldset asked for must still be required.',
        );
        self::assertTrue(
            $this->bodyMatchesGeneratedComponent($body, 'AlbumsDocument', fieldsets: ['albums' => ['title']]),
        );
    }

    #[Test]
    public function aFullResponseStillRequiresEveryAttribute(): void
    {
        $body = self::without($this->jsonApi()->get('/api/albums/1')->assertOk(), 'data.attributes.releasedAt');

        self::assertFalse(
            $this->bodyMatchesGeneratedComponent($body, 'AlbumsDocument'),
            'Without a fieldset every attribute stays required.',
        );

        $sparse = (string) $this->jsonApi()->withFields('albums', 'title')->get('/api/albums/1')->baseResponse->getContent();
        self::assertFalse(
            $this->bodyMatchesGeneratedComponent($sparse, 'AlbumsDocument'),
            'A sparse body only passes when the fieldset is known.',
        );
    }

    #[Test]
    public function eachTypeFollowsItsOwnFieldset(): void
    {
        // The related endpoint hangs off `albums` but its primary data is `artists`.
        $this->assertResponseMatchesGeneratedSchema(
            $this->jsonApi()->withFields('artists', 'name')->get('/api/albums/1/artist')->assertOk(),
            'albums',
            SchemaDocumentKind::Related,
            'artist',
        );

        $body = self::without($this->jsonApi()->get('/api/albums/1/artist')->assertOk(), 'data.attributes.bio');

        self::assertFalse(
            $this->bodyMatchesGeneratedComponent($body, 'AlbumsArtistRelatedDocument', fieldsets: ['albums' => 'title']),
            'An albums fieldset must not relax the artists schema.',
        );
        self::assertTrue(
            $this->bodyMatchesGeneratedComponent($body, 'AlbumsArtistRelatedDocument', fieldsets: ['artists' => 'name']),
        );
    }

    #[Test]
    public function aCompoundSparseResponseValidates(): void
    {
        $this->assertResponseMatchesGeneratedSchema(
            $this->jsonApi()
                ->withInclude('artist')
                ->withFields('albums', 'title', 'artist')
                ->withFields('artists', 'name')
                ->get('/api/albums/1')
                ->assertOk()
                ->assertJsonMissingPath('included.0.attributes.bio'),
            'albums',
            SchemaDocumentKind::Single,
        );
    }

    #[Test]
    public function anIncludedResourceThatDivergesFromItsSchemaFails(): void
    {
        $response = $this->jsonApi()->withInclude('artist')->get('/api/albums/1')->assertOk();
        $this->assertResponseMatchesGeneratedSchema($response, 'albums', SchemaDocumentKind::Single);

        self::assertFalse(
            $this->bodyMatchesGeneratedComponent(self::without($response, 'included.0.attributes.bio'), 'AlbumsDocument'),
            'An included resource must validate against its own type\'s resource schema.',
        );
    }

    #[Test]
    public function anIncludedTypeWithoutAFieldsetStaysStrict(): void
    {
        $response = $this->jsonApi()
            ->withInclude('artist')
            ->withFields('albums', 'title', 'artist')
            ->get('/api/albums/1')
            ->assertOk();
        $this->assertResponseMatchesGeneratedSchema($response, 'albums', SchemaDocumentKind::Single);

        self::assertFalse(
            $this->bodyMatchesGeneratedComponent(
                self::without($response, 'included.0.attributes.bio'),
                'AlbumsDocument',
                fieldsets: ['albums' => 'title,artist'],
            ),
            'An albums fieldset must not relax the included artists.',
        );
    }

    #[Test]
    public function anEmptyFieldsetRelaxesEveryMember(): void
    {
        $full = $this->jsonApi()->withInclude('artist')->get('/api/albums/1')->assertOk();
        self::assertTrue($this->bodyMatchesGeneratedComponent(
            self::without($full, 'included.0.attributes'),
            'AlbumsDocument',
            fieldsets: ['artists' => ''],
        ));
        self::assertFalse($this->bodyMatchesGeneratedComponent(self::without($full, 'included.0.attributes.name'), 'AlbumsDocument'));
    }

    #[Test]
    public function anIncludedTypeWithoutAComponentIsSkipped(): void
    {
        $body = $this->jsonApi()->get('/api/albums/1')->assertOk()->json();
        self::assertIsArray($body);
        $body['included'] = [['type' => 'not-a-registered-type', 'id' => '1', 'attributes' => ['anything' => 1]]];

        self::assertTrue($this->bodyMatchesGeneratedComponent(\json_encode($body, \JSON_THROW_ON_ERROR), 'AlbumsDocument'));
    }

    #[Test]
    public function explicitFieldsetsOverrideTheRequest(): void
    {
        $response = $this->jsonApi()->withFields('albums', 'title')->get('/api/albums/1')->assertOk();

        self::assertFalse(
            $this->bodyMatchesGeneratedComponent((string) $response->baseResponse->getContent(), 'AlbumsDocument', fieldsets: []),
        );
        $this->assertResponseMatchesGeneratedSchema($response, 'albums', SchemaDocumentKind::Single, fieldsets: ['albums' => 'title']);
    }

    /**
     * The response body with the member at a dot `$path` removed.
     *
     * @param TestResponse<\Symfony\Component\HttpFoundation\Response> $response
     */
    private static function without(TestResponse $response, string $path): string
    {
        $body = $response->json();
        self::assertIsArray($body);
        Arr::forget($body, $path);

        return \json_encode($body, \JSON_THROW_ON_ERROR);
    }
}
