<?php

declare(strict_types=1);

namespace haddowg\JsonApiLaravel\Action;

/**
 * The resolved, cacheable declaration of a single custom action — the
 * {@see \haddowg\JsonApiLaravel\Attribute\AsJsonApiAction} metadata flattened into the
 * values the {@see ActionInvoker}, the route registrar and the OpenAPI metadata source
 * consume, with the decoupled-document defaults already applied: `inputType`/`outputType`
 * resolve to the mount `type` when the attribute left them `null`.
 *
 * `handlerClass` is the class-string of the {@see ActionHandlerInterface} that handles
 * the action, resolved lazily through the container by the {@see ActionRegistry} so a
 * handler with real constructor dependencies is constructed only when its action is
 * actually invoked. `server` is the (already-resolved) server name the action is exposed
 * on (the implicit `default` when the attribute left it `null`).
 *
 * `output` is the declared success-response shape ({@see ActionOutput}) the OpenAPI
 * projection advertises: a resource document (with `outputType`), a meta-only document,
 * or a `204`. When it is not {@see ActionOutput::Document}, `outputType` carries the
 * empty-string sentinel (there is no response resource).
 *
 * It carries only scalars + lists (enums by case name), so a set of descriptors
 * round-trips through {@see toArray()} / {@see fromArray()} to a `var_export`-able
 * discovery cache file, keeping route registration a pure function of the cached
 * snapshot (`route:cache`-safe).
 */
final readonly class ActionDescriptor
{
    /**
     * @param class-string<ActionHandlerInterface> $handlerClass the handler class-string (constructed lazily via the container)
     * @param list<string>                         $methods      the author-declared HTTP method allow-list
     * @param list<string>                         $tags         the OpenAPI tag refs (empty = inherit the mount type's default)
     * @param bool                                 $asLink       expose the action as an ability-aware `links` member (resource scope only)
     */
    public function __construct(
        public string $type,
        public string $path,
        public array $methods,
        public ActionScope $scope,
        public ActionInput $input,
        public string $inputType,
        public string $outputType,
        public ActionOutput $output,
        public ?string $ability,
        public string $handlerClass,
        public string $server,
        public ?string $name = null,
        public array $tags = [],
        public bool $asLink = false,
    ) {}

    /**
     * @return array{type: string, path: string, methods: list<string>, scope: string, input: string, inputType: string, outputType: string, output: string, ability: ?string, handlerClass: class-string<ActionHandlerInterface>, server: string, name: ?string, tags: list<string>, asLink: bool}
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
            'output' => $this->output->name,
            'ability' => $this->ability,
            'handlerClass' => $this->handlerClass,
            'server' => $this->server,
            'name' => $this->name,
            'tags' => $this->tags,
            'asLink' => $this->asLink,
        ];
    }

    /**
     * @param array{type: string, path: string, methods: list<string>, scope: string, input: string, inputType: string, outputType: string, output: string, ability?: ?string, handlerClass: class-string<ActionHandlerInterface>, server: string, name?: ?string, tags?: list<string>, asLink?: bool} $data
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
            ActionOutput::{$data['output']},
            $data['ability'] ?? null,
            $data['handlerClass'],
            $data['server'],
            $data['name'] ?? null,
            $data['tags'] ?? [],
            $data['asLink'] ?? false,
        );
    }
}
