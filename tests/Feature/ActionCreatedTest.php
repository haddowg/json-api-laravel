<?php

declare(strict_types=1);

namespace haddowg\JsonApiLaravel\Tests\Feature;

use haddowg\JsonApi\OpenApi\GeneratorContract;
use haddowg\JsonApiLaravel\JsonApiServiceProvider;
use haddowg\JsonApiLaravel\OpenApi\DocumentFactory;
use haddowg\JsonApiLaravel\Tests\Fixtures\ActionCreated\ActionCreatedServiceProvider;
use haddowg\JsonApiLaravel\Tests\Support\InteractsWithOpenApiDocument;
use Illuminate\Testing\TestResponse;
use Orchestra\Testbench\TestCase as Orchestra;
use PHPUnit\Framework\Attributes\CoversNothing;
use PHPUnit\Framework\Attributes\Test;

/**
 * Custom actions declaring `ActionCreated`: the response is sent as `201` when the created
 * document is the only one the action declares, the handler picks when it declares a `200`
 * document too, and the generated document advertises the `201`.
 *
 * @internal
 */
#[CoversNothing]
final class ActionCreatedTest extends Orchestra
{
    use InteractsWithOpenApiDocument;

    /**
     * @param \Illuminate\Foundation\Application $app
     *
     * @return array<int, class-string>
     */
    protected function getPackageProviders($app): array
    {
        return [JsonApiServiceProvider::class, ActionCreatedServiceProvider::class];
    }

    #[Test]
    public function anActionDeclaringOnlyACreatedDocumentAnswers201(): void
    {
        $response = $this->postAction('/api/orders/1/-actions/duplicate');

        $response->assertStatus(201);
        $response->assertHeader('Location', 'http://localhost/api/orders/1-copy');
        $response->assertJsonPath('data.type', 'orders');
        $response->assertJsonPath('data.id', '1-copy');
    }

    #[Test]
    public function anActionDeclaringBothDocumentsAnswersTheStatusItsHandlerPicks(): void
    {
        $this->postAction('/api/orders/1/-actions/restore')->assertStatus(200);
        $this->postAction('/api/orders/2/-actions/restore')->assertStatus(201);
    }

    #[Test]
    public function theDocumentAdvertisesThe201(): void
    {
        $doc = $this->resolve(DocumentFactory::class)->forServer('default')->toArray();

        $duplicate = $this->arrayAt($doc, 'paths', '/orders/{id}/-actions/duplicate', 'post', 'responses');
        self::assertArrayNotHasKey('200', $duplicate);
        self::assertSame('The resource the action created.', $this->at($duplicate, '201', 'description'));
        self::assertIsArray($this->at($duplicate, '201', 'headers', 'Location'));

        $restore = $this->arrayAt($doc, 'paths', '/orders/{id}/-actions/restore', 'post', 'responses');
        self::assertArrayHasKey('200', $restore);
        self::assertArrayHasKey('201', $restore);

        self::assertSame(\max(GeneratorContract::BASELINE, GeneratorContract::ACTION_CREATED), $this->at($doc, 'info', 'x-generator', 'contract'));
    }

    /**
     * @return TestResponse<\Symfony\Component\HttpFoundation\Response>
     */
    private function postAction(string $uri): TestResponse
    {
        return $this->call('POST', $uri, [], [], [], ['HTTP_ACCEPT' => 'application/vnd.api+json', 'CONTENT_TYPE' => 'application/vnd.api+json']);
    }
}
