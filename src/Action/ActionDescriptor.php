<?php

declare(strict_types=1);

namespace haddowg\JsonApiLaravel\Action;

use haddowg\JsonApi\OpenApi\Metadata\Accepted;
use haddowg\JsonApi\OpenApi\Metadata\ActionResource;
use haddowg\JsonApi\OpenApi\Metadata\ActionResponse;
use haddowg\JsonApi\OpenApi\Metadata\ActionResponses;
use haddowg\JsonApi\OpenApi\Metadata\MetaResult;
use haddowg\JsonApi\OpenApi\Metadata\NoContent;
use haddowg\JsonApi\OpenApi\Metadata\RawResult;
use haddowg\JsonApi\OpenApi\Metadata\SeeOther;

/**
 * The resolved, cacheable declaration of a single custom action — the
 * {@see \haddowg\JsonApiLaravel\Attribute\AsJsonApiAction} metadata flattened into the
 * values the {@see ActionInvoker}, the route registrar and the OpenAPI metadata source
 * consume, with the decoupled-document defaults already applied: `inputType` resolves to
 * the mount `type` when the attribute left it `null`.
 *
 * `handlerClass` is the class-string of the {@see ActionHandlerInterface} that handles
 * the action, resolved lazily through the container by the {@see ActionRegistry} so a
 * handler with real constructor dependencies is constructed only when its action is
 * actually invoked. `server` is the (already-resolved) server name the action is exposed
 * on (the implicit `default` when the attribute left it `null`).
 *
 * `responds` is the declared success-response set the OpenAPI projection advertises, each
 * element a scalar `{kind, ref}` pair round-tripping the atomic core response objects
 * ({@see \haddowg\JsonApi\OpenApi\Metadata\ActionResource} etc.): `kind` is `resource`
 * (a `200` document, `ref` = the resource type), `accepted` (a `202`, `ref` = the job
 * type), `meta` (a `200` meta document), `nocontent` (a `204`), or `seeother` (a `303`,
 * also carrying its `description` and `mediaType`; a `mediaType` opts the action in to
 * answering with a URL).
 * `outputType` is the resolved serializer type every {@see \haddowg\JsonApi\Response\DataResponse}
 * the handler returns renders through — the `resource` response's type when one is declared,
 * else the mount `type`.
 *
 * `readOnly`, `destructive` and `idempotent` are the action's declared safety flags, `null`
 * when undeclared, `extensions` its own OpenAPI vendor extensions, and `scopes` the OAuth
 * scopes it declares, `null` when the scope resolver decides.
 *
 * It carries only scalars + lists (enums by case name), so a set of descriptors
 * round-trips through {@see toArray()} / {@see fromArray()} to a `var_export`-able
 * discovery cache file, keeping route registration a pure function of the cached
 * snapshot (`route:cache`-safe).
 */
final readonly class ActionDescriptor
{
    /**
     * @param class-string<ActionHandlerInterface>            $handlerClass the handler class-string (constructed lazily via the container)
     * @param list<string>                                    $methods      the author-declared HTTP method allow-list
     * @param non-empty-list<array{kind: string, ref: string|null, description?: string|null, mediaType?: string|null}> $responds the declared success-response set (kind + optional type/job-type ref)
     * @param list<string>                                    $tags         the OpenAPI tag refs (empty = inherit the mount type's default)
     * @param bool                                            $asLink       expose the action as an ability-aware `links` member (resource scope only)
     * @param bool                                            $resolvesTrashed resolve the `{id}` target through the trashed-inclusive fetch ({@see \haddowg\JsonApiLaravel\DataProvider\FetchesTrashed}) rather than the strict, trashed-excluding one — the synthesized soft-delete restore/force-delete actions set this
     * @param string|null                                     $summary      the operation's OpenAPI summary (null keeps the generated one)
     * @param string|null                                     $description  the operation's OpenAPI description (null keeps the generated one)
     * @param array<string, string>                           $accepts      the accepted request media types, lowercased, each mapped to the {@see ActionInput} case name it is read in (empty = what the input mode implies)
     * @param array<string, mixed>                            $extensions   the action operation's own OpenAPI vendor extensions, keyed by `x-` name
     * @param list<string>|null                               $scopes       the OAuth scopes the action declares (null = ask the scope resolver)
     * @param bool                                            $conditionallyLinked the handler is {@see ConditionallyLinked}, so its `asLink` link is further gated per rendered entity by `shouldLink()` (recorded here so the link contributor resolves the handler only when it matters)
     */
    public function __construct(
        public string $type,
        public string $path,
        public array $methods,
        public ActionScope $scope,
        public ActionInput $input,
        public string $inputType,
        public string $outputType,
        public array $responds,
        public ?string $ability,
        public string $handlerClass,
        public string $server,
        public ?string $name = null,
        public array $tags = [],
        public bool $asLink = false,
        public bool $resolvesTrashed = false,
        public bool $conditionallyLinked = false,
        public ?string $summary = null,
        public ?string $description = null,
        public array $accepts = [],
        public ?bool $readOnly = null,
        public ?bool $destructive = null,
        public ?bool $idempotent = null,
        public array $extensions = [],
        public ?array $scopes = null,
    ) {}

    /**
     * The media types the action can answer with, as core's `ActionResponses::mediaTypes()`
     * lists them: the JSON:API media type first when any response is a JSON:API one, then
     * each raw result's media type once, in declared order.
     *
     * @return list<string>
     */
    public function responseMediaTypes(): array
    {
        return ActionResponses::mediaTypes($this->declaredResponses());
    }

    /**
     * The declared responses as core's response objects, which core's negotiation and
     * dispatch read. A raw result keeps only its media type here; the projection reads its
     * full declaration off the handler's attribute.
     *
     * @return non-empty-list<ActionResponse>
     */
    public function declaredResponses(): array
    {
        $responses = [];
        foreach ($this->responds as $response) {
            $responses[] = match ($response['kind']) {
                'resource' => new ActionResource((string) $response['ref']),
                'accepted' => new Accepted((string) $response['ref']),
                'meta' => new MetaResult(),
                'nocontent' => new NoContent(),
                'seeother' => new SeeOther($response['description'] ?? null, $response['mediaType'] ?? null),
                'raw' => new RawResult((string) $response['ref']),
                default => throw new \LogicException(\sprintf(
                    'Unknown action response kind "%s" for action "%s" on type "%s".',
                    $response['kind'],
                    $this->path,
                    $this->type,
                )),
            };
        }

        return $responses;
    }

    /**
     * The input mode a request whose `Content-Type` matched the `$accepted` entry is read in.
     */
    public function inputFor(string $accepted): ActionInput
    {
        $mode = $this->accepts[$accepted] ?? null;

        return $mode === null ? $this->input : ActionInput::{$mode};
    }

    /**
     * @return array{type: string, path: string, methods: list<string>, scope: string, input: string, inputType: string, outputType: string, responds: non-empty-list<array{kind: string, ref: string|null, description?: string|null, mediaType?: string|null}>, ability: ?string, handlerClass: class-string<ActionHandlerInterface>, server: string, name: ?string, tags: list<string>, asLink: bool, resolvesTrashed: bool, conditionallyLinked: bool, summary: ?string, description: ?string, accepts: array<string, string>, readOnly: ?bool, destructive: ?bool, idempotent: ?bool, extensions: array<string, mixed>, scopes: list<string>|null}
     */
    public function toArray(): array
    {
        return [
            'type' => $this->type,
            'path' => $this->path,
            'methods' => $this->methods,
            'scope' => $this->scope->name,
            'input' => $this->input->name,
            'inputType' => $this->inputType,
            'outputType' => $this->outputType,
            'responds' => $this->responds,
            'ability' => $this->ability,
            'handlerClass' => $this->handlerClass,
            'server' => $this->server,
            'name' => $this->name,
            'tags' => $this->tags,
            'asLink' => $this->asLink,
            'resolvesTrashed' => $this->resolvesTrashed,
            'conditionallyLinked' => $this->conditionallyLinked,
            'summary' => $this->summary,
            'description' => $this->description,
            'accepts' => $this->accepts,
            'readOnly' => $this->readOnly,
            'destructive' => $this->destructive,
            'idempotent' => $this->idempotent,
            'extensions' => $this->extensions,
            'scopes' => $this->scopes,
        ];
    }

    /**
     * @param array{type: string, path: string, methods: list<string>, scope: string, input: string, inputType: string, outputType: string, responds: non-empty-list<array{kind: string, ref: string|null, description?: string|null, mediaType?: string|null}>, ability?: ?string, handlerClass: class-string<ActionHandlerInterface>, server: string, name?: ?string, tags?: list<string>, asLink?: bool, resolvesTrashed?: bool, conditionallyLinked?: bool, summary?: ?string, description?: ?string, accepts?: array<string, string>, readOnly?: ?bool, destructive?: ?bool, idempotent?: ?bool, extensions?: array<string, mixed>, scopes?: list<string>|null} $data
     */
    public static function fromArray(array $data): self
    {
        return new self(
            $data['type'],
            $data['path'],
            $data['methods'],
            ActionScope::{$data['scope']},
            ActionInput::{$data['input']},
            $data['inputType'],
            $data['outputType'],
            $data['responds'],
            $data['ability'] ?? null,
            $data['handlerClass'],
            $data['server'],
            $data['name'] ?? null,
            $data['tags'] ?? [],
            $data['asLink'] ?? false,
            $data['resolvesTrashed'] ?? false,
            $data['conditionallyLinked'] ?? false,
            $data['summary'] ?? null,
            $data['description'] ?? null,
            $data['accepts'] ?? [],
            $data['readOnly'] ?? null,
            $data['destructive'] ?? null,
            $data['idempotent'] ?? null,
            $data['extensions'] ?? [],
            $data['scopes'] ?? null,
        );
    }
}
