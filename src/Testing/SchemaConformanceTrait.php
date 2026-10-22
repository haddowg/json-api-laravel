<?php

declare(strict_types=1);

namespace haddowg\JsonApiLaravel\Testing;

use haddowg\JsonApi\OpenApi\ComponentNaming;
use haddowg\JsonApiLaravel\OpenApi\DocumentFactory;
use Illuminate\Contracts\Foundation\Application;
use Illuminate\Http\Request;
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
 * no meta-schema needs registering and the helper is fully offline.
 *
 * **Sparse fieldsets.** The generated read schemas list every attribute as `required`,
 * because a full response carries them all. A response to a `?fields[<type>]=…` request
 * legitimately omits the members outside the fieldset, so for each type the request
 * names, the helpers validate against a copy of the document whose `<Type>` attributes
 * (and relationships) require only the members in that type's fieldset. A type the
 * request does not name keeps its full `required` set, and a member that is in the
 * fieldset but missing from the body still fails. The fieldsets come from the request
 * behind a {@see TestResponse}; pass `$fieldsets` to supply (or override) them, which a
 * raw body string needs.
 *
 * When opis is
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
     * @param array<string, string|list<string>>|null                         $fieldsets    the sparse fieldsets the response was rendered under, keyed by type (`['albums' => 'title,artist']` or `['albums' => ['title', 'artist']]`); null reads them from the request behind a {@see TestResponse}
     */
    protected function assertResponseMatchesGeneratedSchema(
        TestResponse|string $response,
        string $type,
        SchemaDocumentKind $kind,
        ?string $relationship = null,
        ?string $server = null,
        ?array $fieldsets = null,
    ): void {
        $component = $kind->componentName($type, $relationship);

        if ($response instanceof TestResponse) {
            $fieldsets ??= self::requestFieldsets($response);
            $response = (string) $response->baseResponse->getContent();
        }

        $this->assertBodyMatchesGeneratedComponent($response, $component, $server, $fieldsets);
    }

    /**
     * The lower-level seam: validate a raw JSON body against a **named** generated
     * component by its bare component name (e.g. `AlbumsResource`, a custom enum
     * component, or a per-relation `RelatedCollection`). The kind-based
     * {@see assertResponseMatchesGeneratedSchema()} is the ergonomic front door; this is
     * the escape hatch for a component the four standard kinds do not name.
     *
     * @param array<string, string|list<string>>|null $fieldsets the sparse fieldsets the body was rendered under, keyed by type
     */
    protected function assertBodyMatchesGeneratedComponent(string $body, string $component, ?string $server = null, ?array $fieldsets = null): void
    {
        if ($this->schemaConformanceSkippedWithoutOpis()) {
            return;
        }

        $result = $this->validateBody($body, $component, $server, $fieldsets ?? []);

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
     *
     * @param array<string, string|list<string>>|null $fieldsets the sparse fieldsets the body was rendered under, keyed by type
     */
    protected function bodyMatchesGeneratedComponent(string $body, string $component, ?string $server = null, ?array $fieldsets = null): bool
    {
        $this->schemaConformanceSkippedWithoutOpis();

        return $this->validateBody($body, $component, $server, $fieldsets ?? [])->isValid();
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

    /**
     * @param array<string, string|list<string>> $fieldsets
     */
    private function validateBody(string $body, string $component, ?string $server, array $fieldsets): ValidationResult
    {
        $decoded = \json_decode($body, false, 512, \JSON_THROW_ON_ERROR);

        $document = $this->generatedDocument($server);
        $fieldsets = self::normaliseFieldsets($fieldsets);
        if ($fieldsets !== []) {
            $document = self::relaxedForFieldsets($document, $fieldsets);
        }

        $validator = new Validator();
        $resolver = $validator->resolver();
        \assert($resolver !== null);
        $resolver->registerRaw($document, self::documentId());

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

    /**
     * The `fields` query of the request behind `$response`, as the framework parsed it.
     *
     * @param TestResponse<\Symfony\Component\HttpFoundation\Response> $response
     *
     * @return array<string, string|list<string>>
     */
    private static function requestFieldsets(TestResponse $response): array
    {
        $request = $response->baseRequest;
        if (!$request instanceof Request) {
            return [];
        }

        $fields = $request->query('fields');
        if (!\is_array($fields)) {
            return [];
        }

        $fieldsets = [];
        foreach ($fields as $type => $members) {
            if (\is_string($members)) {
                $fieldsets[(string) $type] = $members;
            }
        }

        return $fieldsets;
    }

    /**
     * @param array<string, string|list<string>> $fieldsets
     *
     * @return array<string, list<string>> the member names per type, an empty fieldset kept as an empty list
     */
    private static function normaliseFieldsets(array $fieldsets): array
    {
        $normalised = [];
        foreach ($fieldsets as $type => $members) {
            $names = \is_string($members) ? \explode(',', $members) : $members;
            $normalised[$type] = \array_values(\array_filter(\array_map('trim', $names), static fn(string $name): bool => $name !== ''));
        }

        return $normalised;
    }

    /**
     * A copy of `$document` in which each fieldset type's response attributes and
     * relationships require only the members in its fieldset. The cached document is
     * never mutated, and a type with no `<Type>Resource` component is ignored.
     *
     * @param array<string, list<string>> $fieldsets
     */
    private static function relaxedForFieldsets(\stdClass $document, array $fieldsets): \stdClass
    {
        $copy = \json_decode(\json_encode($document, \JSON_THROW_ON_ERROR), false, 512, \JSON_THROW_ON_ERROR);
        \assert($copy instanceof \stdClass);

        $schemas = $copy->components->schemas ?? null;
        if (!$schemas instanceof \stdClass) {
            return $copy;
        }

        foreach ($fieldsets as $type => $members) {
            $resource = $schemas->{ComponentNaming::base($type) . 'Resource'} ?? null;
            if (!$resource instanceof \stdClass || !($resource->properties ?? null) instanceof \stdClass) {
                continue;
            }

            foreach (['attributes', 'relationships'] as $member) {
                $schema = self::resolveLocalRef($schemas, $resource->properties->{$member} ?? null);
                if ($schema !== null) {
                    self::keepRequired($schema, $members);
                }
            }
        }

        return $copy;
    }

    /**
     * The object schema `$schema` names, following one `#/components/schemas/…` `$ref`.
     */
    private static function resolveLocalRef(\stdClass $schemas, mixed $schema): ?\stdClass
    {
        if (!$schema instanceof \stdClass) {
            return null;
        }

        $ref = $schema->{'$ref'} ?? null;
        if (!\is_string($ref)) {
            return $schema;
        }

        $prefix = '#/components/schemas/';
        if (!\str_starts_with($ref, $prefix)) {
            return null;
        }

        $target = $schemas->{\substr($ref, \strlen($prefix))} ?? null;

        return $target instanceof \stdClass ? $target : null;
    }

    /**
     * @param list<string> $members
     */
    private static function keepRequired(\stdClass $schema, array $members): void
    {
        if (!\is_array($schema->required ?? null)) {
            return;
        }

        $required = \array_values(\array_filter(
            $schema->required,
            static fn(mixed $name): bool => \in_array($name, $members, true),
        ));

        if ($required === []) {
            unset($schema->required);
        } else {
            $schema->required = $required;
        }
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
