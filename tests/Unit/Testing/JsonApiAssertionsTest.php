<?php

declare(strict_types=1);

namespace haddowg\JsonApiLaravel\Tests\Unit\Testing;

use haddowg\JsonApi\Testing\JsonApiDocument;
use haddowg\JsonApi\Testing\JsonApiErrors;
use haddowg\JsonApiLaravel\Testing\InteractsWithJsonApi;
use Illuminate\Testing\TestResponse;
use PHPUnit\Framework\AssertionFailedError;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpFoundation\Response;

/**
 * Unit coverage of the JSON:API {@see TestResponse} macros
 * ({@see \haddowg\JsonApiLaravel\Testing\JsonApiAssertions}) over hand-built responses:
 * every macro **passes** on a conforming document and **fails** (a normal PHPUnit
 * assertion failure) on a non-conforming one — the teeth that make the kit trustworthy.
 * No HTTP kernel is booted; the macros are exercised against synthetic
 * {@see Response}-backed {@see TestResponse}s so the assertion logic is proven in
 * isolation.
 *
 * @internal
 */
#[CoversClass(\haddowg\JsonApiLaravel\Testing\JsonApiAssertions::class)]
final class JsonApiAssertionsTest extends TestCase
{
    use InteractsWithJsonApi;

    protected function setUp(): void
    {
        parent::setUp();
        self::registerJsonApiMacros();
    }

    #[Test]
    public function assertJsonApiDocumentPassesOnADataDocumentAndExposesTheWrapper(): void
    {
        $response = $this->jsonApiResponse('{"data":{"type":"albums","id":"1","attributes":{"title":"Kid A"}}}');

        $seen = null;
        $response->assertJsonApiDocument(function (JsonApiDocument $document) use (&$seen): void {
            $document->assertHasType('albums')->assertHasId('1')->assertHasAttribute('title', 'Kid A');
            $seen = $document;
        });

        self::assertInstanceOf(JsonApiDocument::class, $seen);
    }

    #[Test]
    public function assertJsonApiDocumentFailsOnAnErrorDocument(): void
    {
        $response = $this->jsonApiResponse('{"errors":[{"status":"404"}]}', 404);

        $this->assertMacroFails(
            static fn() => $response->assertJsonApiDocument(),
            'assertJsonApiDocument must reject an error document.',
        );
    }

    #[Test]
    public function assertJsonApiDocumentFailsOnAWrongContentType(): void
    {
        $response = $this->responseWith('{"data":{"type":"albums","id":"1"}}', 200, 'application/json');

        $this->assertMacroFails(
            static fn() => $response->assertJsonApiDocument(),
            'assertJsonApiDocument must reject a non-JSON:API content type.',
        );
    }

    #[Test]
    public function assertJsonApiErrorPassesOnAnErrorDocumentWithTheStatusAndCallback(): void
    {
        $response = $this->jsonApiResponse('{"errors":[{"status":"422","source":{"pointer":"/data/attributes/title"}}]}', 422);

        $response->assertJsonApiError(422, static function (JsonApiErrors $errors): void {
            $errors->assertHasError(status: '422')->assertHasErrorAt('/data/attributes/title');
        });
    }

    #[Test]
    public function assertJsonApiErrorFailsOnADataDocument(): void
    {
        $response = $this->jsonApiResponse('{"data":{"type":"albums","id":"1"}}');

        $this->assertMacroFails(
            static fn() => $response->assertJsonApiError(),
            'assertJsonApiError must reject a data document.',
        );
    }

    #[Test]
    public function assertJsonApiErrorFailsWhenTheStatusMismatches(): void
    {
        $response = $this->jsonApiResponse('{"errors":[{"status":"404"}]}', 404);

        $this->assertMacroFails(
            static fn() => $response->assertJsonApiError(422),
            'assertJsonApiError must reject a mismatched status.',
        );
    }

    #[Test]
    public function assertFetchedOnePassesOnASingleResourceAndFailsOnACollection(): void
    {
        $this->jsonApiResponse('{"data":{"type":"albums","id":"1"}}')->assertFetchedOne();

        $collection = $this->jsonApiResponse('{"data":[{"type":"albums","id":"1"}]}');
        $this->assertMacroFails(
            static fn() => $collection->assertFetchedOne(),
            'assertFetchedOne must reject a collection document.',
        );
    }

    #[Test]
    public function assertFetchedManyPassesOnACollectionAndFailsOnASingleResource(): void
    {
        $this->jsonApiResponse('{"data":[{"type":"albums","id":"1"},{"type":"albums","id":"2"}]}')
            ->assertFetchedMany(static fn(JsonApiDocument $document) => $document->assertCollectionCount(2));

        $single = $this->jsonApiResponse('{"data":{"type":"albums","id":"1"}}');
        $this->assertMacroFails(
            static fn() => $single->assertFetchedMany(),
            'assertFetchedMany must reject a single-resource document.',
        );
    }

    #[Test]
    public function assertJsonApiSpecCompliantPassesOnACompliantDocumentAndFailsOnAMalformedOne(): void
    {
        // A resource object needs a `type`; the compliant body carries one.
        $this->jsonApiResponse('{"data":{"type":"albums","id":"1","attributes":{"title":"Kid A"}}}')
            ->assertJsonApiSpecCompliant();

        // A resource object missing its `type` is not spec-compliant.
        $malformed = $this->jsonApiResponse('{"data":{"id":"1","attributes":{"title":"Kid A"}}}');
        $this->assertMacroFails(
            static fn() => $malformed->assertJsonApiSpecCompliant(),
            'assertJsonApiSpecCompliant must reject a document whose resource object omits `type`.',
        );
    }

    #[Test]
    public function theAccessorMacrosReturnTheCoreWrappers(): void
    {
        $document = $this->jsonApiResponse('{"data":{"type":"albums","id":"1"}}')->jsonApiDocument();
        self::assertInstanceOf(JsonApiDocument::class, $document);
        $document->assertHasType('albums');

        $errors = $this->jsonApiResponse('{"errors":[{"status":"404"}]}', 404)->jsonApiErrors();
        self::assertInstanceOf(JsonApiErrors::class, $errors);
        $errors->assertHasError(status: '404');
    }

    /**
     * A JSON:API response backed by a synthetic {@see Response} carrying the JSON:API
     * media type.
     *
     * @return TestResponse<Response>
     */
    private function jsonApiResponse(string $body, int $status = 200): TestResponse
    {
        return $this->responseWith($body, $status, JsonApiAssertionsTest::MEDIA_TYPE);
    }

    /**
     * @return TestResponse<Response>
     */
    private function responseWith(string $body, int $status, string $contentType): TestResponse
    {
        return new TestResponse(new Response($body, $status, ['Content-Type' => $contentType]));
    }

    private const string MEDIA_TYPE = 'application/vnd.api+json';

    /**
     * Asserts `$assertion` raises a PHPUnit assertion failure — the way a kit macro
     * reports a non-conforming document — rather than passing silently.
     *
     * @param \Closure(): mixed $assertion
     */
    private function assertMacroFails(\Closure $assertion, string $because): void
    {
        try {
            $assertion();
        } catch (AssertionFailedError) {
            $this->addToAssertionCount(1);

            return;
        }

        self::fail($because);
    }
}
