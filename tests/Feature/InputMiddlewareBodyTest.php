<?php

declare(strict_types=1);

namespace haddowg\JsonApiLaravel\Tests\Feature;

use haddowg\JsonApi\Atomic\AtomicExtension;
use haddowg\JsonApiLaravel\JsonApiServiceProvider;
use haddowg\JsonApiLaravel\Tests\Eloquent\EloquentTestCase;
use haddowg\JsonApiLaravel\Tests\Fixtures\InputMiddleware\InputMiddlewareServiceProvider;
use Illuminate\Contracts\Http\Kernel;
use Illuminate\Foundation\Http\Middleware\ConvertEmptyStringsToNull;
use Illuminate\Foundation\Http\Middleware\TrimStrings;
use Illuminate\Support\Facades\DB;
use Illuminate\Testing\TestResponse;
use PHPUnit\Framework\Attributes\CoversNothing;
use PHPUnit\Framework\Attributes\DataProvider;
use Workbench\App\Providers\EloquentWorkbenchServiceProvider;

/**
 * Laravel's default global middleware (`TrimStrings`, `ConvertEmptyStringsToNull`) rewrite
 * the decoded JSON input bag. A JSON:API request body must reach core as the client sent
 * it, so a write answers the same with that middleware on as with it off.
 *
 * @internal
 */
#[CoversNothing]
final class InputMiddlewareBodyTest extends EloquentTestCase
{
    private const string ATOMIC_MEDIA_TYPE = 'application/vnd.api+json; ext="' . AtomicExtension::URI . '"';

    /**
     * @param \Illuminate\Foundation\Application $app
     *
     * @return array<int, class-string>
     */
    protected function getPackageProviders($app): array
    {
        return [
            JsonApiServiceProvider::class,
            EloquentWorkbenchServiceProvider::class,
            InputMiddlewareServiceProvider::class,
        ];
    }

    /**
     * @param \Illuminate\Foundation\Application $app
     */
    protected function defineEnvironment($app): void
    {
        parent::defineEnvironment($app);

        /** @var \Illuminate\Contracts\Config\Repository $config */
        $config = $app['config'];
        $config->set('jsonapi.atomic_operations.enabled', true);
    }

    protected function setUp(): void
    {
        parent::setUp();
        $this->seedFixtures();

        // Testbench's default HTTP kernel carries both; without them these tests prove nothing.
        $kernel = $this->app?->make(Kernel::class);
        self::assertInstanceOf(\Illuminate\Foundation\Http\Kernel::class, $kernel);
        self::assertTrue($kernel->hasMiddleware(TrimStrings::class));
        self::assertTrue($kernel->hasMiddleware(ConvertEmptyStringsToNull::class));
    }

    public function test_a_create_keeps_padded_and_empty_strings(): void
    {
        $this->send('POST', '/api/albums', self::MEDIA_TYPE, self::albumDocument())
            ->assertCreated()
            ->assertJsonPath('data.attributes.title', '  Spaced  ')
            ->assertJsonPath('data.attributes.status', '');
    }

    public function test_an_update_keeps_padded_and_empty_strings(): void
    {
        $this->send('PATCH', '/api/albums/1', self::MEDIA_TYPE, self::albumDocument('1'))
            ->assertOk()
            ->assertJsonPath('data.attributes.title', '  Spaced  ')
            ->assertJsonPath('data.attributes.status', '');

        $this->get('/api/albums/1', ['Accept' => self::MEDIA_TYPE])
            ->assertJsonPath('data.attributes.title', '  Spaced  ')
            ->assertJsonPath('data.attributes.status', '');
    }

    public function test_a_relationship_mutation_still_applies(): void
    {
        $this->send('PATCH', '/api/albums/1/relationships/artist', self::MEDIA_TYPE, '{"data":{"type":"artists","id":"2"}}')
            ->assertOk()
            ->assertJsonPath('data.id', '2');
    }

    public function test_an_atomic_batch_keeps_padded_and_empty_strings(): void
    {
        $this->send('POST', '/api/operations', self::ATOMIC_MEDIA_TYPE, self::atomicDocument())
            ->assertOk()
            ->assertJsonPath('atomic:results.0.data.attributes.title', '  Spaced  ')
            ->assertJsonPath('atomic:results.0.data.attributes.status', '')
            ->assertJsonPath('atomic:results.1.data.attributes.title', ' Padded ')
            ->assertJsonPath('atomic:results.1.data.attributes.status', '');
    }

    public function test_a_document_action_hydrates_padded_and_empty_strings(): void
    {
        $this->send('POST', '/api/albums/-actions/echo-document', self::MEDIA_TYPE, self::albumDocument())
            ->assertOk()
            ->assertJsonPath('meta.title', '  Spaced  ')
            ->assertJsonPath('meta.status', '');
    }

    public function test_a_raw_action_gets_its_exact_bytes(): void
    {
        $json = '{ "data" : { "title" : "  Spaced  ", "status" : "" } }';

        $this->send('POST', '/api/albums/-actions/echo-raw', self::MEDIA_TYPE, $json)
            ->assertOk()
            ->assertJsonPath('meta.raw', $json)
            ->assertJsonPath('meta.parsed.data.title', '  Spaced  ')
            ->assertJsonPath('meta.parsed.data.status', '');

        $this->send('POST', '/api/albums/-actions/echo-raw', 'text/plain', "  padded\n")
            ->assertOk()
            ->assertJsonPath('meta.raw', "  padded\n");
    }

    public function test_malformed_json_is_rejected_as_invalid_json(): void
    {
        $response = $this->send('POST', '/api/albums', self::MEDIA_TYPE, '{"data": ')
            ->assertStatus(400);

        self::assertSame(self::MALFORMED_ERROR, $response->json('errors.0'));
    }

    public function test_a_scalar_body_is_still_a_document_missing_its_top_level_members(): void
    {
        $this->send('POST', '/api/albums', self::MEDIA_TYPE, '"  x  "')
            ->assertStatus(400)
            ->assertJsonPath('errors.0.code', 'REQUIRED_TOP_LEVEL_MEMBERS_MISSING');
    }

    /**
     * The error core raises for the body `{"data": `, unchanged by reading the raw content.
     *
     * @var array<string, mixed>
     */
    private const array MALFORMED_ERROR = [
        'meta' => ['original' => '{"data": '],
        'status' => '400',
        'code' => 'REQUEST_BODY_INVALID_JSON',
        'title' => 'Request body is an invalid JSON document',
        'detail' => "Request body is an invalid JSON document: 'Syntax error'!",
    ];

    /**
     * @return iterable<string, array{string, string, string, string}>
     */
    public static function requests(): iterable
    {
        yield 'create' => ['POST', '/api/albums', self::MEDIA_TYPE, self::albumDocument()];
        yield 'update' => ['PATCH', '/api/albums/1', self::MEDIA_TYPE, self::albumDocument('1')];
        yield 'replace to-one' => ['PATCH', '/api/albums/1/relationships/artist', self::MEDIA_TYPE, '{"data":{"type":"artists","id":"2"}}'];
        yield 'clear to-one' => ['PATCH', '/api/albums/1/relationships/artist', self::MEDIA_TYPE, '{"data":null}'];
        yield 'atomic batch' => ['POST', '/api/operations', self::ATOMIC_MEDIA_TYPE, self::atomicDocument()];
        yield 'document action' => ['POST', '/api/albums/-actions/echo-document', self::MEDIA_TYPE, self::albumDocument()];
        yield 'raw action, JSON:API body' => ['POST', '/api/albums/-actions/echo-raw', self::MEDIA_TYPE, '{"data":{"title":"  Spaced  ","status":""}}'];
        yield 'raw action, plain text' => ['POST', '/api/albums/-actions/echo-raw', 'text/plain', '  padded  '];
        yield 'no-input action with a body' => ['POST', '/api/albums/-actions/echo-none', self::MEDIA_TYPE, self::albumDocument()];
        yield 'malformed create' => ['POST', '/api/albums', self::MEDIA_TYPE, '{"data": '];
        yield 'malformed update' => ['PATCH', '/api/albums/1', self::MEDIA_TYPE, '{"data": '];
        yield 'malformed relationship' => ['PATCH', '/api/albums/1/relationships/artist', self::MEDIA_TYPE, '{"data": '];
        yield 'malformed atomic batch' => ['POST', '/api/operations', self::ATOMIC_MEDIA_TYPE, '{"atomic:operations": '];
        yield 'malformed document action' => ['POST', '/api/albums/-actions/echo-document', self::MEDIA_TYPE, '{"data": '];
        yield 'malformed raw action' => ['POST', '/api/albums/-actions/echo-raw', self::MEDIA_TYPE, '{"data": '];
        yield 'whitespace-only create' => ['POST', '/api/albums', self::MEDIA_TYPE, '   '];
        yield 'scalar create' => ['POST', '/api/albums', self::MEDIA_TYPE, '"  x  "'];
        yield 'null create' => ['POST', '/api/albums', self::MEDIA_TYPE, 'null'];
    }

    #[DataProvider('requests')]
    public function test_a_request_answers_the_same_as_without_the_input_middleware(string $method, string $uri, string $contentType, string $body): void
    {
        // Each write runs against the same seed, so the two answers are comparable.
        DB::beginTransaction();
        $with = $this->send($method, $uri, $contentType, $body);
        DB::rollBack();

        $this->withoutMiddleware([TrimStrings::class, ConvertEmptyStringsToNull::class]);
        $without = $this->send($method, $uri, $contentType, $body);

        self::assertSame($without->getStatusCode(), $with->getStatusCode());
        self::assertSame($without->getContent(), $with->getContent());
    }

    /**
     * @return TestResponse<\Symfony\Component\HttpFoundation\Response>
     */
    private function send(string $method, string $uri, string $contentType, string $body): TestResponse
    {
        $accept = $contentType === self::ATOMIC_MEDIA_TYPE ? self::ATOMIC_MEDIA_TYPE : self::MEDIA_TYPE;

        return $this->call($method, $uri, [], [], [], [
            'HTTP_ACCEPT' => $accept,
            'CONTENT_TYPE' => $contentType,
        ], $body);
    }

    private static function albumDocument(?string $id = null): string
    {
        $data = ['type' => 'albums'];
        if ($id !== null) {
            $data['id'] = $id;
        }
        $data['attributes'] = ['title' => '  Spaced  ', 'status' => '', 'releasedAt' => '1997-05-21T00:00:00+00:00'];
        $data['relationships'] = ['artist' => ['data' => ['type' => 'artists', 'id' => '1']]];

        return (string) \json_encode(['data' => $data]);
    }

    private static function atomicDocument(): string
    {
        return (string) \json_encode(['atomic:operations' => [
            ['op' => 'add', 'data' => [
                'type' => 'albums',
                'attributes' => ['title' => '  Spaced  ', 'status' => '', 'releasedAt' => '1997-05-21T00:00:00+00:00'],
                'relationships' => ['artist' => ['data' => ['type' => 'artists', 'id' => '1']]],
            ]],
            ['op' => 'update', 'ref' => ['type' => 'albums', 'id' => '1'], 'data' => ['type' => 'albums', 'id' => '1', 'attributes' => ['title' => ' Padded ', 'status' => '']]],
        ]]);
    }
}
