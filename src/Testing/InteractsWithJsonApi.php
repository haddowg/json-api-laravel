<?php

declare(strict_types=1);

namespace haddowg\JsonApiLaravel\Testing;

use Illuminate\Testing\TestResponse;

/**
 * The package's shipped JSON:API test-client sugar (PLAN decision 12) — a
 * request-builder over Laravel's native test client plus a family of JSON:API
 * {@see TestResponse} macros. Mix it into a Testbench or Laravel `TestCase`:
 *
 * ```php
 * use haddowg\JsonApiLaravel\Testing\InteractsWithJsonApi;
 * use haddowg\JsonApi\Testing\JsonApiDocument;
 *
 * final class AlbumsTest extends TestCase
 * {
 *     use InteractsWithJsonApi;
 *
 *     public function test_it_lists_albums(): void
 *     {
 *         $this->jsonApi()
 *             ->withInclude('artist')
 *             ->withSort('-releasedAt')
 *             ->get('/api/albums')
 *             ->assertOk()
 *             ->assertFetchedMany(fn (JsonApiDocument $doc) => $doc
 *                 ->assertFetchedManyInOrder(['2', '1'])
 *                 ->assertHasIncluded('artists'));
 *     }
 *
 *     public function test_it_creates_an_album(): void
 *     {
 *         $this->actingAs($this->writer());        // actingAs is native Laravel
 *
 *         $this->jsonApi()
 *             ->withResource('albums', attributes: ['title' => 'Kid A', 'status' => 'released'])
 *             ->post('/api/albums')
 *             ->assertCreated()
 *             ->assertJsonApiDocument(fn (JsonApiDocument $doc) => $doc
 *                 ->assertHasType('albums')
 *                 ->assertHasAttribute('title', 'Kid A'));
 *     }
 *
 *     public function test_a_missing_album_is_a_jsonapi_error(): void
 *     {
 *         $this->jsonApi()->get('/api/albums/404')
 *             ->assertJsonApiError(404, fn ($errors) => $errors->assertHasError(status: '404'));
 *     }
 * }
 * ```
 *
 * **The macros** it registers on {@see TestResponse} (none clash with a native
 * assertion, so they compose in any chain):
 *
 *  - `assertJsonApiDocument(?Closure $with = null)` — a JSON:API **data** document
 *    (content type + a `data`/`meta` member, no `errors`); the closure gets the core
 *    {@see JsonApiDocument}.
 *  - `assertJsonApiError(?int $status = null, ?Closure $with = null)` — a JSON:API
 *    **error** document; the closure gets the core {@see JsonApiErrors}.
 *  - `assertFetchedOne(?Closure $with = null)` / `assertFetchedMany(?Closure $with = null)`
 *    — a `200` single-resource / collection read.
 *  - `assertJsonApiSpecCompliant()` — validates the body against the JSON:API 1.1
 *    JSON Schema (skips when `opis/json-schema` is absent).
 *  - `jsonApiDocument()` / `jsonApiErrors()` — accessors returning the core wrappers
 *    for ad-hoc assertions.
 *
 * **Authentication is native.** There is no auth sugar here: call Laravel's
 * `$this->actingAs($user)` (or `actingAsGuest()`) before issuing the request — the
 * builder dispatches through the same test client, so the acting user is honoured.
 *
 * @phpstan-type JsonApiTestResponse \Illuminate\Testing\TestResponse<\Symfony\Component\HttpFoundation\Response>
 *
 * @mixin \Illuminate\Foundation\Testing\TestCase
 */
trait InteractsWithJsonApi
{
    /**
     * Begins a fluent JSON:API request. Configure the document / query / headers on the
     * returned {@see PendingJsonApiRequest}, then issue a verb
     * (`get()`/`post()`/`patch()`/`delete()`) to dispatch it and get a {@see TestResponse}.
     */
    public function jsonApi(): PendingJsonApiRequest
    {
        self::registerJsonApiMacros();

        return new PendingJsonApiRequest($this->sendJsonApiRequest(...));
    }

    /**
     * Registers the JSON:API {@see TestResponse} macros (delegating to
     * {@see JsonApiAssertions::registerMacros()}). **Idempotent** — safe to call
     * repeatedly. Testbench auto-invokes {@see setUpInteractsWithJsonApi()} for every
     * test using this trait, and {@see jsonApi()} calls it too, so the macros are always
     * available. A plain Laravel `TestCase` that only uses the macros (never
     * {@see jsonApi()}) can call this once in its base `setUp()`.
     */
    public static function registerJsonApiMacros(): void
    {
        JsonApiAssertions::registerMacros();
    }

    /**
     * Testbench trait-lifecycle hook — auto-invoked on `setUp` for every test using this
     * trait (via `Orchestra\Testbench\Concerns\Testing`), so the macros register with no
     * per-test boilerplate.
     */
    protected function setUpInteractsWithJsonApi(): void
    {
        self::registerJsonApiMacros();
    }

    /**
     * Issues the request through Laravel's native test client with the JSON:API media
     * type negotiated on `Accept` (and `Content-Type` when a body is sent). Caller-set
     * headers win over the negotiated defaults.
     *
     * @param array<string, mixed>|null $document
     * @param array<string, string>     $headers
     *
     * @return JsonApiTestResponse
     */
    protected function sendJsonApiRequest(string $method, string $uri, ?array $document, array $headers): TestResponse
    {
        $headers = \array_merge(
            ['Accept' => PendingJsonApiRequest::MEDIA_TYPE],
            $document !== null ? ['CONTENT_TYPE' => PendingJsonApiRequest::MEDIA_TYPE] : [],
            $headers,
        );

        if ($document !== null) {
            return $this->json($method, $uri, $document, $headers);
        }

        return match (\strtoupper($method)) {
            'GET' => $this->get($uri, $headers),
            'DELETE' => $this->delete($uri, [], $headers),
            'HEAD' => $this->head($uri, $headers),
            default => $this->call($method, $uri, [], [], [], $this->jsonApiServerVars($headers)),
        };
    }

    /**
     * Converts an HTTP header map to the `$_SERVER` shape Laravel's `call()` expects
     * (`Content-Type`/`Content-Length`/`Content-Md5` verbatim, everything else prefixed
     * `HTTP_`) — the fallback arm's equivalent of the framework's own
     * `transformHeadersToServerVars()`, inlined so no protected framework method is
     * reached from the trait.
     *
     * @param array<string, string> $headers
     *
     * @return array<string, string>
     */
    private function jsonApiServerVars(array $headers): array
    {
        $server = [];
        foreach ($headers as $name => $value) {
            $key = \strtoupper(\str_replace('-', '_', $name));
            if (!\in_array($key, ['CONTENT_TYPE', 'CONTENT_LENGTH', 'CONTENT_MD5'], true)) {
                $key = 'HTTP_' . $key;
            }
            $server[$key] = $value;
        }

        return $server;
    }
}
