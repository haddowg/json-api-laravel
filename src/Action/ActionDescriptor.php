<?php

declare(strict_types=1);

namespace haddowg\JsonApiLaravel\Action;

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
 * type), `meta` (a `200` meta document), `nocontent` (a `204`), or `seeother` (a `303`).
 * `outputType` is the resolved serializer type every {@see \haddowg\JsonApi\Response\DataResponse}
 * the handler returns renders through — the `resource` response's type when one is declared,
 * else the mount `type`.
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
     * @param non-empty-list<array{kind: string, ref: string|null}> $responds the declared success-response set (kind + optional type/job-type ref)
     * @param list<string>                                    $tags         the OpenAPI tag refs (empty = inherit the mount type's default)
     * @param bool                                            $asLink       expose the action as an ability-aware `links` member (resource scope only)
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
    ) {}

    /**
     * @return array{type: string, path: string, methods: list<string>, scope: string, input: string, inputType: string, outputType: string, responds: non-empty-list<array{kind: string, ref: string|null}>, ability: ?string, handlerClass: class-string<ActionHandlerInterface>, server: string, name: ?string, tags: list<string>, asLink: bool}
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
        ];
    }

    /**
     * @param array{type: string, path: string, methods: list<string>, scope: string, input: string, inputType: string, outputType: string, responds: non-empty-list<array{kind: string, ref: string|null}>, ability?: ?string, handlerClass: class-string<ActionHandlerInterface>, server: string, name?: ?string, tags?: list<string>, asLink?: bool} $data
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
        );
    }
}
