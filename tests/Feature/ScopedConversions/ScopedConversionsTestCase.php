<?php

declare(strict_types=1);

namespace haddowg\JsonApiLaravel\Tests\Feature\ScopedConversions;

use haddowg\JsonApiLaravel\Tests\Fixtures\ScopedConversions\JournalResource;
use Illuminate\Testing\TestResponse;
use Orchestra\Testbench\TestCase as Orchestra;
use PHPUnit\Framework\Attributes\CoversNothing;
use PHPUnit\Framework\Attributes\Test;

/**
 * A conversion declared inside `onCreate()` or `onUpdate()` applies to that write only:
 * `mood` becomes the enum case on a create and stays the string on an update, and `name`
 * is upper-cased on an update and stored as sent on a create. Atomic operations and
 * action input follow the same rule.
 *
 * @internal
 */
#[CoversNothing]
abstract class ScopedConversionsTestCase extends Orchestra
{
    private const string MEDIA_TYPE = 'application/vnd.api+json';

    private const string ATOMIC_MEDIA_TYPE = 'application/vnd.api+json; ext="https://jsonapi.org/ext/atomic"';

    /**
     * @param \Illuminate\Foundation\Application $app
     */
    protected function defineEnvironment($app): void
    {
        /** @var \Illuminate\Contracts\Config\Repository $config */
        $config = $app['config'];
        $config->set('jsonapi.base_uri', 'http://localhost/api');
        $config->set('jsonapi.atomic_operations.enabled', true);
    }

    protected function setUp(): void
    {
        parent::setUp();
        JournalResource::$moods = [];
    }

    #[Test]
    public function aCreateConvertsWhatItsScopeDeclares(): void
    {
        $response = $this->send('POST', '/api/journals', $this->journal(null));

        $response->assertCreated();
        self::assertSame('ada', $response->json('data.attributes.name'));
        self::assertSame([\haddowg\JsonApiLaravel\Tests\Fixtures\ScopedConversions\Mood::class], JournalResource::$moods);
    }

    #[Test]
    public function anUpdateConvertsWhatItsScopeDeclares(): void
    {
        $response = $this->send('PATCH', '/api/journals/1', $this->journal('1'));

        $response->assertOk();
        self::assertSame('ADA', $response->json('data.attributes.name'));
        self::assertSame(['string'], JournalResource::$moods);
    }

    #[Test]
    public function anAtomicOperationConvertsWhatItsWriteDeclares(): void
    {
        $response = $this->send('POST', '/api/operations', ['atomic:operations' => [
            ['op' => 'add', 'data' => $this->journal(null)['data']],
            ['op' => 'update', 'ref' => ['type' => 'journals', 'id' => '1'], 'data' => $this->journal('1')['data']],
        ]], self::ATOMIC_MEDIA_TYPE);

        $response->assertOk();
        self::assertSame('ada', $response->json('atomic:results.0.data.attributes.name'));
        self::assertSame('ADA', $response->json('atomic:results.1.data.attributes.name'));
        self::assertSame([\haddowg\JsonApiLaravel\Tests\Fixtures\ScopedConversions\Mood::class, 'string'], JournalResource::$moods);
    }

    #[Test]
    public function aPostActionsInputConvertsWhatACreateDeclares(): void
    {
        $response = $this->send('POST', '/api/journals/-actions/record', $this->journal(null));

        $response->assertOk();
        self::assertSame('ada', $response->json('meta.name'));
        self::assertSame([\haddowg\JsonApiLaravel\Tests\Fixtures\ScopedConversions\Mood::class], $response->json('meta.moods'));
    }

    #[Test]
    public function aPatchActionsInputConvertsWhatAnUpdateDeclares(): void
    {
        $response = $this->send('PATCH', '/api/journals/-actions/revise', $this->journal('1'));

        $response->assertOk();
        self::assertSame('ADA', $response->json('meta.name'));
        self::assertSame(['string'], $response->json('meta.moods'));
    }

    /**
     * @return array{data: array<string, mixed>}
     */
    private function journal(?string $id): array
    {
        $data = ['type' => 'journals'];
        if ($id !== null) {
            $data['id'] = $id;
        }
        $data['attributes'] = ['name' => 'ada', 'mood' => 'calm'];

        return ['data' => $data];
    }

    /**
     * @param array<string, mixed> $document
     *
     * @return TestResponse<\Symfony\Component\HttpFoundation\Response>
     */
    private function send(string $method, string $uri, array $document, string $mediaType = self::MEDIA_TYPE): TestResponse
    {
        return $this->call($method, $uri, [], [], [], [
            'HTTP_ACCEPT' => $mediaType,
            'CONTENT_TYPE' => $mediaType,
        ], \json_encode($document, \JSON_THROW_ON_ERROR));
    }
}
