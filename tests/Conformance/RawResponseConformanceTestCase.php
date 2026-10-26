<?php

declare(strict_types=1);

namespace haddowg\JsonApiLaravel\Tests\Conformance;

use haddowg\JsonApiLaravel\JsonApiServiceProvider;
use haddowg\JsonApiLaravel\OpenApi\DocumentFactory;
use haddowg\JsonApiLaravel\Tests\Fixtures\RawResponses\RawResponsesServiceProvider;
use Illuminate\Testing\TestResponse;
use Orchestra\Testbench\TestCase as Orchestra;
use PHPUnit\Framework\Attributes\Test;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * A custom action that declares raw results answers in the media type the request's `Accept`
 * picks: a JSON:API document, a CSV string or a streamed PDF. Errors stay JSON:API.
 *
 * @internal
 */
abstract class RawResponseConformanceTestCase extends Orchestra
{
    public const string MEDIA_TYPE = 'application/vnd.api+json';

    abstract protected function conformanceServiceProvider(): string;

    /**
     * @param \Illuminate\Foundation\Application $app
     *
     * @return array<int, class-string>
     */
    protected function getPackageProviders($app): array
    {
        /** @var class-string $provider */
        $provider = $this->conformanceServiceProvider();

        return [JsonApiServiceProvider::class, $provider, RawResponsesServiceProvider::class];
    }

    protected function seedConformanceData(): void {}

    protected function setUp(): void
    {
        parent::setUp();
        $this->seedConformanceData();
    }

    #[Test]
    public function aCsvAcceptGetsTheCsvBody(): void
    {
        $response = $this->export('text/csv');

        $response->assertOk();
        self::assertSame("title\nOK Computer\n", $response->getContent());
        self::assertStringStartsWith('text/csv', (string) $response->headers->get('Content-Type'));
        self::assertSame('attachment; filename="albums.csv"', $response->headers->get('Content-Disposition'));
        self::assertStringContainsString('Accept', (string) $response->headers->get('Vary'));
    }

    #[Test]
    public function aStreamBodyIsStreamed(): void
    {
        $response = $this->export('application/pdf');

        $response->assertOk();
        self::assertInstanceOf(StreamedResponse::class, $response->baseResponse);
        self::assertSame('%PDF-1.7 albums', $response->streamedContent());
        self::assertSame('application/pdf', $response->headers->get('Content-Type'));
    }

    #[Test]
    public function theJsonApiMediaTypeStillGetsTheDocument(): void
    {
        $this->export(self::MEDIA_TYPE)->assertOk()->assertJsonPath('meta.format', 'json');
        $this->export('text/csv;q=0.5, application/vnd.api+json')->assertOk()->assertJsonPath('meta.format', 'json');
    }

    #[Test]
    public function anAcceptTheActionCannotAnswerIs406AsJsonApi(): void
    {
        $response = $this->export('image/png');

        $response->assertStatus(406);
        self::assertSame(self::MEDIA_TYPE, $response->headers->get('Content-Type'));
        $response->assertJsonPath('errors.0.status', '406');
    }

    #[Test]
    public function aBodyInAMediaTypeThatWasNotNegotiatedIsRefused(): void
    {
        $response = $this->get('/api/albums/-actions/mislabelled', ['Accept' => 'text/csv']);

        $response->assertStatus(500);
        self::assertSame(self::MEDIA_TYPE, $response->headers->get('Content-Type'));
    }

    #[Test]
    public function theRawResultsAreProjectedUnderTheSuccessResponse(): void
    {
        $document = $this->app?->make(DocumentFactory::class)->forServer()->toArray();
        self::assertIsArray($document);
        $content = $document;
        foreach (['paths', '/albums/-actions/export', 'get', 'responses', '200', 'content'] as $key) {
            self::assertIsArray($content);
            $content = $content[$key] ?? null;
        }
        self::assertIsArray($content);

        self::assertSame([self::MEDIA_TYPE, 'text/csv', 'application/pdf'], \array_keys($content));
    }

    /**
     * @return TestResponse<\Symfony\Component\HttpFoundation\Response>
     */
    private function export(string $accept): TestResponse
    {
        return $this->get('/api/albums/-actions/export', ['Accept' => $accept]);
    }
}
