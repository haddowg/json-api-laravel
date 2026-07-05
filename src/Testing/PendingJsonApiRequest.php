<?php

declare(strict_types=1);

namespace haddowg\JsonApiLaravel\Testing;

use Illuminate\Testing\TestResponse;

/**
 * A fluent builder for a JSON:API test request, returned by
 * {@see InteractsWithJsonApi::jsonApi()}. It composes the request document and the
 * query/header envelope, then dispatches through Laravel's native test client
 * (negotiating `application/vnd.api+json` on both `Accept` and `Content-Type`) and
 * returns Laravel's {@see TestResponse} — so the JSON:API macros
 * ({@see InteractsWithJsonApi::registerJsonApiMacros()}) and every native Laravel
 * response assertion chain off the same result.
 *
 * ```php
 * // A collection read with query sugar:
 * $this->jsonApi()
 *     ->withInclude('artist')
 *     ->withSort('-releasedAt')
 *     ->withFilter('status', 'released')
 *     ->get('/api/albums')
 *     ->assertFetchedMany();
 *
 * // A create with a resource document (authenticate natively first):
 * $this->actingAs($user);
 * $this->jsonApi()
 *     ->withResource('albums', attributes: ['title' => 'Kid A', 'status' => 'released'])
 *     ->post('/api/albums')
 *     ->assertCreated()
 *     ->assertJsonApiDocument(fn ($doc) => $doc->assertHasType('albums'));
 *
 * // A relationship replace:
 * $this->jsonApi()
 *     ->withData(['type' => 'artists', 'id' => '2'])
 *     ->patch('/api/albums/1/relationships/artist');
 * ```
 *
 * The builder never handles authentication: `actingAs()` is native Laravel (PLAN
 * decision 12) — call `$this->actingAs($user)` on the test case before issuing the
 * request.
 *
 * @phpstan-type JsonApiTestResponse \Illuminate\Testing\TestResponse<\Symfony\Component\HttpFoundation\Response>
 */
final class PendingJsonApiRequest
{
    public const string MEDIA_TYPE = 'application/vnd.api+json';

    /**
     * @var array<string, mixed>|null the request document, or null for a body-less request
     */
    private ?array $document = null;

    /**
     * @var array<string, string> extra headers merged over the negotiated defaults
     */
    private array $headers = [];

    /**
     * @var array<string, string> query parameters appended to the request URI
     */
    private array $query = [];

    /**
     * @param \Closure(string, string, array<string, mixed>|null, array<string, string>): JsonApiTestResponse $sender the callback that issues the request through the test client
     */
    public function __construct(private readonly \Closure $sender) {}

    // --- document builders ---------------------------------------------------

    /**
     * Sets the whole request document verbatim — the escape hatch for a body the
     * higher-level builders below do not shape (e.g. an atomic `atomic:operations`
     * payload).
     *
     * @param array<string, mixed> $document
     */
    public function withDocument(array $document): self
    {
        $this->document = $document;

        return $this;
    }

    /**
     * Sets the top-level `data` member — a resource object, a to-one linkage
     * (`['type' => …, 'id' => …]`), a to-many linkage list, or `null` to clear a
     * relationship. Convenient for relationship-endpoint mutations.
     *
     * @param array<string, mixed>|list<array<string, mixed>>|null $data
     */
    public function withData(array|null $data): self
    {
        $this->document ??= [];
        $this->document['data'] = $data;

        return $this;
    }

    /**
     * Builds and sets `data` as a resource object — the ergonomic front door for a
     * create/update body (mirrors core's `JsonApiRequestBuilder::withResource()`).
     *
     * @param array<string, mixed>                $attributes
     * @param array<string, array<string, mixed>> $relationships keyed by name, each a `{ data: … }` map
     */
    public function withResource(string $type, ?string $id = null, array $attributes = [], array $relationships = []): self
    {
        $resource = ['type' => $type];
        if ($id !== null) {
            $resource['id'] = $id;
        }
        if ($attributes !== []) {
            $resource['attributes'] = $attributes;
        }
        if ($relationships !== []) {
            $resource['relationships'] = $relationships;
        }

        return $this->withData($resource);
    }

    /**
     * Sets the top-level `meta` member.
     *
     * @param array<string, mixed> $meta
     */
    public function withMeta(array $meta): self
    {
        $this->document ??= [];
        $this->document['meta'] = $meta;

        return $this;
    }

    // --- query builders ------------------------------------------------------

    /**
     * Merges query parameters onto the request URI.
     *
     * @param array<string, string> $query
     */
    public function withQuery(array $query): self
    {
        $this->query = [...$this->query, ...$query];

        return $this;
    }

    public function withQueryParam(string $key, string $value): self
    {
        $this->query[$key] = $value;

        return $this;
    }

    /**
     * The `?include` compound-document parameter (comma-joined paths).
     */
    public function withInclude(string ...$paths): self
    {
        return $this->withQueryParam('include', \implode(',', $paths));
    }

    /**
     * A `?fields[<type>]` sparse-fieldset parameter (comma-joined field names).
     */
    public function withFields(string $type, string ...$fields): self
    {
        return $this->withQueryParam("fields[{$type}]", \implode(',', $fields));
    }

    /**
     * The `?sort` parameter (comma-joined fields; prefix a field with `-` to descend).
     */
    public function withSort(string ...$fields): self
    {
        return $this->withQueryParam('sort', \implode(',', $fields));
    }

    /**
     * A `?filter[<key>]` parameter.
     */
    public function withFilter(string $key, string $value): self
    {
        return $this->withQueryParam("filter[{$key}]", $value);
    }

    /**
     * The `?page[<key>]` pagination parameters.
     *
     * @param array<string, int|string> $page
     */
    public function withPage(array $page): self
    {
        foreach ($page as $key => $value) {
            $this->withQueryParam("page[{$key}]", (string) $value);
        }

        return $this;
    }

    // --- header builders -----------------------------------------------------

    public function withHeader(string $name, string $value): self
    {
        $this->headers[$name] = $value;

        return $this;
    }

    /**
     * @param array<string, string> $headers
     */
    public function withHeaders(array $headers): self
    {
        $this->headers = [...$this->headers, ...$headers];

        return $this;
    }

    /**
     * Adds a media-type `profile` parameter to the negotiated `Accept` header — the
     * client opt-in for an advisory profile (e.g. the Countable `?withCount` profile).
     */
    public function withProfile(string ...$uris): self
    {
        return $this->withHeader('Accept', self::MEDIA_TYPE . ';profile="' . \implode(' ', $uris) . '"');
    }

    // --- terminal verbs ------------------------------------------------------

    /**
     * Issues a body-less JSON:API `GET`.
     *
     * @return JsonApiTestResponse
     */
    public function get(string $uri): TestResponse
    {
        return $this->send('GET', $uri, null);
    }

    /**
     * Issues a JSON:API `POST`, sending the built document (if any) as the body.
     *
     * @return JsonApiTestResponse
     */
    public function post(string $uri): TestResponse
    {
        return $this->send('POST', $uri, $this->document);
    }

    /**
     * Issues a JSON:API `PATCH`, sending the built document (if any) as the body.
     *
     * @return JsonApiTestResponse
     */
    public function patch(string $uri): TestResponse
    {
        return $this->send('PATCH', $uri, $this->document);
    }

    /**
     * Issues a JSON:API `DELETE`. A resource delete carries no body; a relationship
     * remove sends the built linkage document.
     *
     * @return JsonApiTestResponse
     */
    public function delete(string $uri): TestResponse
    {
        return $this->send('DELETE', $uri, $this->document);
    }

    /**
     * @param array<string, mixed>|null $document
     *
     * @return JsonApiTestResponse
     */
    private function send(string $method, string $uri, ?array $document): TestResponse
    {
        return ($this->sender)($method, $this->applyQuery($uri), $document, $this->headers);
    }

    private function applyQuery(string $uri): string
    {
        if ($this->query === []) {
            return $uri;
        }

        return $uri . (\str_contains($uri, '?') ? '&' : '?') . \http_build_query($this->query);
    }
}
