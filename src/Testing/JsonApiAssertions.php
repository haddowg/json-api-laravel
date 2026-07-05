<?php

declare(strict_types=1);

namespace haddowg\JsonApiLaravel\Testing;

use haddowg\JsonApi\Testing\JsonApiDocument;
use haddowg\JsonApi\Testing\JsonApiErrors;
use haddowg\JsonApi\Testing\ResponseMeta;
use haddowg\JsonApi\Testing\SpecCompliance;
use Illuminate\Testing\TestResponse;
use PHPUnit\Framework\Assert;

/**
 * The engine behind the JSON:API {@see TestResponse} macros registered by
 * {@see InteractsWithJsonApi::registerJsonApiMacros()}. Each macro is a one-line
 * closure delegating to a static method here, so the assertion logic is plain,
 * unit-testable, and analysable at PHPStan level 9 without relying on macro `$this`
 * binding.
 *
 * Every method bridges Laravel's {@see TestResponse} (a decoded body plus the HTTP
 * status + header envelope) to core's framework-agnostic fluent assertion families
 * ({@see JsonApiDocument} / {@see JsonApiErrors}) — the same families the Symfony
 * bundle's `JsonApiBrowser` feeds — so a consumer's Laravel test asserts over the
 * exact same JSON:API vocabulary.
 *
 * @phpstan-type JsonApiTestResponse \Illuminate\Testing\TestResponse<\Symfony\Component\HttpFoundation\Response>
 *
 * @internal not part of the package's public API; use the macros via {@see InteractsWithJsonApi}
 */
final class JsonApiAssertions
{
    public const string MEDIA_TYPE = 'application/vnd.api+json';

    /**
     * Registers the JSON:API {@see TestResponse} macros — the concrete registration the
     * {@see InteractsWithJsonApi} trait, the Testbench setUp hook, and the PHPStan
     * bootstrap all route through. **Idempotent.**
     *
     * Each macro is a thin closure delegating to a static method on this class; the
     * declared return types let larastan derive the macro signatures by reflecting the
     * registered closures.
     */
    public static function registerMacros(): void
    {
        if (TestResponse::hasMacro('assertJsonApiDocument')) {
            return;
        }

        TestResponse::macro('assertJsonApiDocument', function (?\Closure $callback = null): TestResponse {
            /** @var TestResponse<\Symfony\Component\HttpFoundation\Response> $this */
            return JsonApiAssertions::document($this, $callback);
        });

        TestResponse::macro('assertJsonApiError', function (?int $status = null, ?\Closure $callback = null): TestResponse {
            /** @var TestResponse<\Symfony\Component\HttpFoundation\Response> $this */
            return JsonApiAssertions::error($this, $status, $callback);
        });

        TestResponse::macro('assertFetchedOne', function (?\Closure $callback = null): TestResponse {
            /** @var TestResponse<\Symfony\Component\HttpFoundation\Response> $this */
            return JsonApiAssertions::fetchedOne($this, $callback);
        });

        TestResponse::macro('assertFetchedMany', function (?\Closure $callback = null): TestResponse {
            /** @var TestResponse<\Symfony\Component\HttpFoundation\Response> $this */
            return JsonApiAssertions::fetchedMany($this, $callback);
        });

        TestResponse::macro('assertJsonApiSpecCompliant', function (): TestResponse {
            /** @var TestResponse<\Symfony\Component\HttpFoundation\Response> $this */
            return JsonApiAssertions::specCompliant($this);
        });

        TestResponse::macro('jsonApiDocument', function (): JsonApiDocument {
            /** @var TestResponse<\Symfony\Component\HttpFoundation\Response> $this */
            return JsonApiAssertions::documentOf($this);
        });

        TestResponse::macro('jsonApiErrors', function (): JsonApiErrors {
            /** @var TestResponse<\Symfony\Component\HttpFoundation\Response> $this */
            return JsonApiAssertions::errorsOf($this);
        });
    }

    /**
     * Asserts `$response` is a JSON:API **data** document: the JSON:API content type,
     * a top-level `data` or `meta` member, and no `errors`. The optional `$callback`
     * receives the core {@see JsonApiDocument} for further fluent body assertions
     * (`assertHasType()`, `assertFetchedManyInOrder()`, `assertFetchedOneExact()`, …).
     *
     * @param JsonApiTestResponse                  $response
     * @param \Closure(JsonApiDocument): void|null $callback
     *
     * @return JsonApiTestResponse
     */
    public static function document(TestResponse $response, ?\Closure $callback = null): TestResponse
    {
        $document = self::documentOf($response);
        $document->assertContentType(self::MEDIA_TYPE);

        $decoded = $document->toArray();
        Assert::assertArrayNotHasKey(
            'errors',
            $decoded,
            'Expected a JSON:API data document, but the response body is an error document.',
        );
        Assert::assertTrue(
            \array_key_exists('data', $decoded) || \array_key_exists('meta', $decoded),
            'The response body is not a JSON:API document (no top-level `data` or `meta` member).',
        );

        if ($callback !== null) {
            $callback($document);
        }

        return $response;
    }

    /**
     * Asserts `$response` is a JSON:API **error** document: the JSON:API content type
     * and a non-empty top-level `errors` array. `$status`, when given, asserts the HTTP
     * status; the optional `$callback` receives the core {@see JsonApiErrors} for
     * further assertions (`assertHasError()`, `assertHasErrorAt()`, `assertErrorsExact()`, …).
     *
     * @param JsonApiTestResponse                $response
     * @param \Closure(JsonApiErrors): void|null $callback
     *
     * @return JsonApiTestResponse
     */
    public static function error(TestResponse $response, ?int $status = null, ?\Closure $callback = null): TestResponse
    {
        $errors = self::errorsOf($response);
        $errors->assertContentType(self::MEDIA_TYPE);
        Assert::assertNotEmpty($errors->errors(), 'The response body carries no JSON:API `errors`.');

        if ($status !== null) {
            $errors->assertStatus($status);
        }

        if ($callback !== null) {
            $callback($errors);
        }

        return $response;
    }

    /**
     * Asserts a `200 OK` single-resource read: the JSON:API content type and a single
     * primary resource object (not a collection). The optional `$callback` receives the
     * {@see JsonApiDocument}.
     *
     * @param JsonApiTestResponse                  $response
     * @param \Closure(JsonApiDocument): void|null $callback
     *
     * @return JsonApiTestResponse
     */
    public static function fetchedOne(TestResponse $response, ?\Closure $callback = null): TestResponse
    {
        $response->assertOk();
        $document = self::documentOf($response);
        $document->assertContentType(self::MEDIA_TYPE);

        $data = $document->data();
        Assert::assertIsArray($data, 'The response has no primary `data`.');
        Assert::assertFalse(
            \array_is_list($data),
            'The primary `data` is a collection, not a single resource object.',
        );

        if ($callback !== null) {
            $callback($document);
        }

        return $response;
    }

    /**
     * Asserts a `200 OK` collection read: the JSON:API content type and a primary
     * `data` list of resource objects. The optional `$callback` receives the
     * {@see JsonApiDocument} (e.g. `assertFetchedManyInOrder()` / `assertCollectionCount()`).
     *
     * @param JsonApiTestResponse                  $response
     * @param \Closure(JsonApiDocument): void|null $callback
     *
     * @return JsonApiTestResponse
     */
    public static function fetchedMany(TestResponse $response, ?\Closure $callback = null): TestResponse
    {
        $response->assertOk();
        $document = self::documentOf($response);
        $document->assertContentType(self::MEDIA_TYPE);
        $document->assertFetchedMany();

        if ($callback !== null) {
            $callback($document);
        }

        return $response;
    }

    /**
     * Asserts `$response`'s body is JSON:API 1.1 spec-compliant, validating it against
     * the bundled JSON:API JSON Schema via core's {@see SpecCompliance}.
     *
     * The validation needs the optional `opis/json-schema` package; when it is absent
     * the assertion **skips** (never fails) — matching the kit's opis-gated posture —
     * so a suite without opis stays green and a suite with it gets the check.
     *
     * @param JsonApiTestResponse $response
     *
     * @return JsonApiTestResponse
     */
    public static function specCompliant(TestResponse $response): TestResponse
    {
        if (!\class_exists(\Opis\JsonSchema\Validator::class)) {
            Assert::markTestSkipped(
                'opis/json-schema is not installed; JSON:API spec-compliance validation was skipped. '
                . 'Add opis/json-schema (a require-dev / suggest of this package) to enable it.',
            );
        }

        SpecCompliance::assert(self::body($response));

        return $response;
    }

    /**
     * The last response as a core {@see JsonApiDocument}, carrying the HTTP status +
     * header envelope so the transport assertions work alongside the body ones.
     *
     * @param JsonApiTestResponse $response
     */
    public static function documentOf(TestResponse $response): JsonApiDocument
    {
        return JsonApiDocument::of(self::body($response), meta: self::responseMeta($response));
    }

    /**
     * The last response as a core {@see JsonApiErrors}, carrying the same envelope.
     *
     * @param JsonApiTestResponse $response
     */
    public static function errorsOf(TestResponse $response): JsonApiErrors
    {
        return JsonApiErrors::of(self::body($response), meta: self::responseMeta($response));
    }

    /**
     * The plain-scalar response envelope (status + a flattened, case-insensitive header
     * map) the core assertion families read.
     *
     * @param JsonApiTestResponse $response
     */
    private static function responseMeta(TestResponse $response): ResponseMeta
    {
        $base = $response->baseResponse;

        $headers = [];
        foreach ($base->headers->all() as $name => $values) {
            $present = \array_filter($values, static fn(?string $value): bool => $value !== null);
            $headers[$name] = \implode(', ', $present);
        }

        return new ResponseMeta($base->getStatusCode(), $headers);
    }

    /**
     * @param JsonApiTestResponse $response
     */
    private static function body(TestResponse $response): string
    {
        return (string) $response->baseResponse->getContent();
    }
}
