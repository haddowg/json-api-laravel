<?php

declare(strict_types=1);

namespace haddowg\JsonApiLaravel\Tests\Feature;

use haddowg\JsonApi\Testing\JsonApiDocument;
use haddowg\JsonApi\Testing\JsonApiErrors;
use haddowg\JsonApiLaravel\Testing\InteractsWithJsonApi;
use PHPUnit\Framework\Attributes\CoversNothing;
use PHPUnit\Framework\Attributes\Test;

/**
 * End-to-end coverage of the testing kit (PLAN decision 12) over the real in-memory
 * workbench kernel: the {@see InteractsWithJsonApi} request builder assembles correct
 * JSON:API requests (media-type negotiation, document + query + header sugar) and the
 * {@see \haddowg\JsonApiLaravel\Testing\JsonApiAssertions} macros assert real responses
 * — proving the kit is ergonomic against the same negotiate → dispatch → render path a
 * consumer app drives. The macros' failure behaviour is unit-tested in
 * {@see \haddowg\JsonApiLaravel\Tests\Unit\Testing\JsonApiAssertionsTest}.
 *
 * @internal
 */
#[CoversNothing]
final class TestingKitTest extends TestCase
{
    use InteractsWithJsonApi;

    protected const string COUNTABLE_PROFILE = 'https://haddowg.github.io/json-api/profiles/countable/';

    #[Test]
    public function itReadsACollectionNegotiatingTheJsonApiMediaType(): void
    {
        $this->jsonApi()->get('/api/artists')
            ->assertOk()
            ->assertHeader('Content-Type', self::MEDIA_TYPE)
            ->assertFetchedMany(fn(JsonApiDocument $document) => $document
                ->assertCollectionCount(2)
                ->assertCollectionContains('artists', '1'));
    }

    #[Test]
    public function theSortSugarAppliesTheSortParameter(): void
    {
        // Ascending byte order: "Portishead" (2) sorts before "Radiohead" (1).
        $this->jsonApi()->withSort('name')->get('/api/artists')
            ->assertFetchedMany(fn(JsonApiDocument $document) => $document->assertFetchedManyInOrder(['2', '1'], 'artists'));

        $this->jsonApi()->withSort('-name')->get('/api/artists')
            ->assertFetchedMany(fn(JsonApiDocument $document) => $document->assertFetchedManyInOrder(['1', '2'], 'artists'));
    }

    #[Test]
    public function theIncludeSugarExpandsTheCompoundDocument(): void
    {
        $this->jsonApi()->withInclude('artist')->get('/api/albums')
            ->assertFetchedMany(fn(JsonApiDocument $document) => $document->assertHasIncluded('artists'));
    }

    #[Test]
    public function theFieldsSugarLimitsTheSparseFieldset(): void
    {
        $response = $this->jsonApi()->withFields('albums', 'title')->get('/api/albums')->assertOk();

        /** @var array<string, mixed> $attributes */
        $attributes = $response->json('data.0.attributes');
        self::assertArrayHasKey('title', $attributes);
        self::assertArrayNotHasKey('status', $attributes);
    }

    #[Test]
    public function anArbitraryQueryParameterReachesTheServerAndIsRejected(): void
    {
        $this->jsonApi()->withQueryParam('bogus', '1')->get('/api/artists')
            ->assertJsonApiError(400, fn(JsonApiErrors $errors) => $errors->assertHasError(status: '400'));
    }

    #[Test]
    public function theProfileSugarNegotiatesTheMediaTypeProfile(): void
    {
        // The Countable profile opt-in + `?withCount=_self_` is a 400 until a resource
        // opts in — proving `withProfile()` threads the profile onto `Accept` and the
        // query param rides alongside it.
        $this->jsonApi()
            ->withProfile(self::COUNTABLE_PROFILE)
            ->withQueryParam('withCount', '_self_')
            ->get('/api/artists')
            ->assertJsonApiError(400, fn(JsonApiErrors $errors) => $errors->assertHasErrorWithCode('RELATIONSHIP_COUNT_NOT_ALLOWED'));
    }

    #[Test]
    public function itCreatesAResourceFromAResourceDocument(): void
    {
        $this->jsonApi()
            ->withResource('albums', attributes: [
                'title' => 'A Kit Album',
                'status' => 'released',
                'releasedAt' => '2020-02-02T00:00:00+00:00',
            ])
            ->post('/api/albums')
            ->assertCreated()
            ->assertJsonApiDocument(fn(JsonApiDocument $document) => $document
                ->assertHasType('albums')
                ->assertHasAttribute('title', 'A Kit Album'));
    }

    #[Test]
    public function itUpdatesAResource(): void
    {
        $this->jsonApi()
            ->withResource('albums', '1', attributes: ['title' => 'Edited By The Kit'])
            ->patch('/api/albums/1')
            ->assertOk()
            ->assertJsonApiDocument(fn(JsonApiDocument $document) => $document->assertHasAttribute('title', 'Edited By The Kit'));
    }

    #[Test]
    public function itDeletesAResourceWithABodyLessRequest(): void
    {
        $this->jsonApi()->delete('/api/albums/1')->assertNoContent();
        $this->jsonApi()->get('/api/albums/1')->assertJsonApiError(404);
    }

    #[Test]
    public function theDataSugarClearsAToOneRelationshipEndpoint(): void
    {
        $response = $this->jsonApi()->withData(null)->patch('/api/albums/1/relationships/artist')->assertOk();

        self::assertNull($response->json('data'));
    }

    #[Test]
    public function specComplianceAndAccessorMacrosWorkOnARealResponse(): void
    {
        $this->jsonApi()->get('/api/artists/1')->assertJsonApiSpecCompliant();

        $document = $this->jsonApi()->get('/api/artists/1')->jsonApiDocument();
        $document->assertHasType('artists')->assertHasId('1');
    }
}
