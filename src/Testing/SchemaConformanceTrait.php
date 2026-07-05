<?php

declare(strict_types=1);

namespace haddowg\JsonApiLaravel\Testing;

use haddowg\JsonApi\OpenApi\ComponentNaming;
use haddowg\JsonApiLaravel\OpenApi\DocumentFactory;
use Illuminate\Contracts\Foundation\Application;
use Illuminate\Testing\TestResponse;
use Opis\JsonSchema\Errors\ErrorFormatter;
use Opis\JsonSchema\Errors\ValidationError;
use Opis\JsonSchema\ValidationResult;
use Opis\JsonSchema\Validator;
use PHPUnit\Framework\Assert;

/**
 * The round-trip conformance guarantee (PLAN decision 12, ported from the bundle's
 * `SchemaConformanceTrait`): assert a **real** API response validates against the
 * **generated** OpenAPI component schema for its type — proving the document this
 * package produces actually describes the responses it serves, so the byte-compatible
 * OpenAPI contract has teeth in a test suite, not just at export time.
 *
 * Mix it into any Testbench / Laravel `TestCase` (the package's own conformance suite
 * uses it across the in-memory and Eloquent kernels; a consumer app uses it the same
 * way). It builds the document once per server via the package's {@see DocumentFactory}
 * — the same factory the warmer, the controller and the artisan export use, so the
 * validated schema is byte-for-byte the served one — caches it, then validates the
 * response body against the chosen envelope component's internal `$ref`.
 *
 * ```php
 * $this->jsonApi()->get('/api/albums/1')->assertOk();
 * $this->assertResponseMatchesGeneratedSchema($response, 'albums', SchemaDocumentKind::Single);
 * ```
 *
 * Validation runs over **`opis/json-schema`** (a package `require-dev` + `suggest`),
 * which implements the JSON Schema 2020-12 dialect the projection targets natively — so
 * no meta-schema needs registering and the helper is fully offline. When opis is
 * **absent** the assertions **skip** (never fail), matching the kit's opis-gated posture
 * (blueprint §6): a suite without opis stays green, a suite with it gets the check.
 *
 * @mixin \Illuminate\Foundation\Testing\TestCase
 */
trait SchemaConformanceTrait
{
    /** @var array<string, \stdClass> the built document per server, registered under {@see documentId()} */
    private array $generatedDocuments = [];

    /**
     * Assert `$response`'s JSON body validates against the generated component schema for
     * `$type`'s `$kind` document.
     *
     * @param TestResponse<\Symfony\Component\HttpFoundation\Response>|string $response     the test response, or a raw JSON body string
     * @param string                                                         $type         the JSON:API primary type (e.g. `albums`) — for a related/relationship kind this is the type the endpoint hangs off
     * @param SchemaDocumentKind                                             $kind         which document envelope the response carries
     * @param string|null                                                    $relationship the relation name — required for {@see SchemaDocumentKind::Relationship} / {@see SchemaDocumentKind::Related}
     * @param string|null                                                    $server       the JSON:API server whose document to validate against (the implicit `default` server when null)
     */
    protected function assertResponseMatchesGeneratedSchema(
        TestResponse|string $response,
        string $type,
        SchemaDocumentKind $kind,
        ?string $relationship = null,
        ?string $server = null,
    ): void {
        $component = $kind->componentName($type, $relationship);

        $this->assertBodyMatchesGeneratedComponent(
            $response instanceof TestResponse ? (string) $response->baseResponse->getContent() : $response,
            $component,
            $server,
        );
    }

    /**
     * The lower-level seam: validate a raw JSON body against a **named** generated
     * component by its bare component name (e.g. `AlbumsResource`, a custom enum
     * component, or a per-relation `RelatedCollection`). The kind-based
     * {@see assertResponseMatchesGeneratedSchema()} is the ergonomic front door; this is
     * the escape hatch for a component the four standard kinds do not name.
     */
    protected function assertBodyMatchesGeneratedComponent(string $body, string $component, ?string $server = null): void
    {
        if ($this->schemaConformanceSkippedWithoutOpis()) {
            return;
        }

        $result = $this->validateBody($body, $component, $server);

        Assert::assertTrue(
            $result->isValid(),
            \sprintf(
                "The response does not validate against the generated \"%s\" component schema:\n%s",
                $component,
                $this->describeErrors($result->error()),
            ),
        );
    }

    /**
     * The boolean form: does `$body` validate against the named generated component? The
     * assertion helpers above are the front door; this is for a test that needs to prove
     * validation **fails** for a deliberately-invalid body (the conformance guarantee
     * having teeth), where an assertion would be inverted.
     *
     * When opis is absent the test is skipped (the call never returns).
     */
    protected function bodyMatchesGeneratedComponent(string $body, string $component, ?string $server = null): bool
    {
        $this->schemaConformanceSkippedWithoutOpis();

        return $this->validateBody($body, $component, $server)->isValid();
    }

    /**
     * Skips the current test (returning `true`) when `opis/json-schema` is not installed,
     * so the conformance assertions degrade gracefully rather than fatal on a missing
     * class. Returns `false` when opis is present and validation should proceed.
     */
    private function schemaConformanceSkippedWithoutOpis(): bool
    {
        if (\class_exists(Validator::class)) {
            return false;
        }

        Assert::markTestSkipped(
            'opis/json-schema is not installed; the OpenAPI schema-conformance check was skipped. '
            . 'Add opis/json-schema (a require-dev / suggest of this package) to enable it.',
        );
    }

    private function validateBody(string $body, string $component, ?string $server): ValidationResult
    {
        $decoded = \json_decode($body, false, 512, \JSON_THROW_ON_ERROR);

        $validator = new Validator();
        $resolver = $validator->resolver();
        \assert($resolver !== null);
        $resolver->registerRaw($this->generatedDocument($server), self::documentId());

        return $validator->validate($decoded, self::documentId() . ComponentNaming::schemaRef($component));
    }

    /**
     * The generated document for `$server` as the `stdClass` opis validates against —
     * built once per server (the build is pure) and cached. Decoding to `stdClass` (not
     * an assoc array) is required: an empty schema `{}` collapses to `[]` under an assoc
     * decode and breaks validation.
     */
    private function generatedDocument(?string $server): \stdClass
    {
        $key = $server ?? '';

        return $this->generatedDocuments[$key] ??= $this->factory()->forServer($server)->toJson();
    }

    private function factory(): DocumentFactory
    {
        $app = $this->app;
        Assert::assertInstanceOf(Application::class, $app);

        $factory = $app->make(DocumentFactory::class);
        Assert::assertInstanceOf(DocumentFactory::class, $factory);

        return $factory;
    }

    private static function documentId(): string
    {
        return 'urn:haddowg:json-api:openapi:conformance';
    }

    /**
     * A readable, pointer-keyed rendering of the opis validation errors for the failure
     * message: each entry is `<data pointer>: <message>` so the assertion names exactly
     * which response member diverged from the schema.
     */
    private function describeErrors(?ValidationError $error): string
    {
        if ($error === null) {
            return '(no error detail)';
        }

        $keyed = (new ErrorFormatter())->formatKeyed(
            $error,
            static fn(ValidationError $e): string => $e->message(),
        );

        $lines = [];
        foreach ($keyed as $pointer => $messages) {
            foreach ((array) $messages as $message) {
                $lines[] = \sprintf('  %s: %s', $pointer === '' ? '/' : $pointer, $message);
            }
        }

        return $lines === [] ? '  (no pointer detail)' : \implode("\n", $lines);
    }
}
