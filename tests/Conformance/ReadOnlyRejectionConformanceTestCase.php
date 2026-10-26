<?php

declare(strict_types=1);

namespace haddowg\JsonApiLaravel\Tests\Conformance;

use haddowg\JsonApi\Atomic\AtomicExtension;
use haddowg\JsonApiLaravel\JsonApiServiceProvider;
use Illuminate\Testing\TestResponse;
use Orchestra\Testbench\Attributes\DefineEnvironment;
use Orchestra\Testbench\TestCase as Orchestra;
use PHPUnit\Framework\Attributes\Test;

/**
 * With `reject_read_only_attributes` on, a write body that sends a read-only attribute is a
 * `403` naming it, ahead of any validation error; off, the attribute is ignored. `albums`
 * declares `averageRating` read-only and `title` required.
 *
 * @internal
 */
abstract class ReadOnlyRejectionConformanceTestCase extends Orchestra
{
    public const string MEDIA_TYPE = 'application/vnd.api+json';

    private const string ATOMIC = 'application/vnd.api+json; ext="' . AtomicExtension::URI . '"';

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

        return [JsonApiServiceProvider::class, $provider];
    }

    /**
     * @param \Illuminate\Foundation\Application $app
     */
    protected function defineEnvironment($app): void
    {
        /** @var \Illuminate\Contracts\Config\Repository $config */
        $config = $app['config'];
        $config->set('jsonapi.atomic_operations.enabled', true);
        $config->set('jsonapi.reject_read_only_attributes', true);
    }

    /**
     * The `default` server's own setting, which wins over the global one.
     *
     * @param \Illuminate\Foundation\Application $app
     */
    protected function allowReadOnlyAttributes($app): void
    {
        /** @var \Illuminate\Contracts\Config\Repository $config */
        $config = $app['config'];
        $config->set('jsonapi.servers.default.reject_read_only_attributes', false);
    }

    protected function seedConformanceData(): void {}

    protected function setUp(): void
    {
        parent::setUp();
        $this->seedConformanceData();
    }

    #[Test]
    public function aCreateSendingAReadOnlyAttributeIs403(): void
    {
        $response = $this->send('POST', '/api/albums', ['data' => ['type' => 'albums', 'attributes' => [
            'title' => 'Kid A',
            'status' => 'released',
            'releasedAt' => '2000-10-02T00:00:00+00:00',
            'averageRating' => 5,
        ]]]);

        $response->assertForbidden();
        $response->assertJsonPath('errors.0.code', 'ATTRIBUTE_WRITE_PROHIBITED');
        $response->assertJsonPath('errors.0.source.pointer', '/data/attributes/averageRating');
        $this->get('/api/albums/3', ['Accept' => self::MEDIA_TYPE])->assertNotFound();
    }

    #[Test]
    public function theRefusalWinsOverAValidationError(): void
    {
        $this->send('POST', '/api/albums', ['data' => ['type' => 'albums', 'attributes' => ['averageRating' => 5]]])
            ->assertForbidden()
            ->assertJsonCount(1, 'errors')
            ->assertJsonPath('errors.0.code', 'ATTRIBUTE_WRITE_PROHIBITED');
    }

    #[Test]
    public function anUpdateSendingAReadOnlyAttributeIs403(): void
    {
        $this->send('PATCH', '/api/albums/1', ['data' => ['type' => 'albums', 'id' => '1', 'attributes' => ['title' => 'Renamed', 'averageRating' => 1]]])
            ->assertForbidden()
            ->assertJsonPath('errors.0.source.pointer', '/data/attributes/averageRating');

        $this->get('/api/albums/1', ['Accept' => self::MEDIA_TYPE])->assertOk()->assertJsonMissingExact(['title' => 'Renamed']);
    }

    #[Test]
    public function anAtomicOperationSendingAReadOnlyAttributeIs403(): void
    {
        $body = (string) \json_encode(['atomic:operations' => [
            ['op' => 'update', 'ref' => ['type' => 'albums', 'id' => '1'], 'data' => ['type' => 'albums', 'id' => '1', 'attributes' => ['averageRating' => 1]]],
        ]]);

        $this->call('POST', '/api/operations', [], [], [], ['HTTP_ACCEPT' => self::ATOMIC, 'CONTENT_TYPE' => self::ATOMIC], $body)
            ->assertForbidden()
            ->assertJsonPath('errors.0.code', 'ATTRIBUTE_WRITE_PROHIBITED');
    }

    #[Test]
    #[DefineEnvironment('allowReadOnlyAttributes')]
    public function aReadOnlyAttributeIsIgnoredWhenTheServerAllowsIt(): void
    {
        $this->send('PATCH', '/api/albums/1', ['data' => ['type' => 'albums', 'id' => '1', 'attributes' => ['title' => 'Renamed', 'averageRating' => 1]]])
            ->assertOk()
            ->assertJsonPath('data.attributes.title', 'Renamed');
    }

    /**
     * @param array<string, mixed> $body
     *
     * @return TestResponse<\Symfony\Component\HttpFoundation\Response>
     */
    private function send(string $method, string $uri, array $body): TestResponse
    {
        return $this->call($method, $uri, [], [], [], ['HTTP_ACCEPT' => self::MEDIA_TYPE, 'CONTENT_TYPE' => self::MEDIA_TYPE], (string) \json_encode($body));
    }
}
