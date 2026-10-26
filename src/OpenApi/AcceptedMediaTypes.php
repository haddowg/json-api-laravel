<?php

declare(strict_types=1);

namespace haddowg\JsonApiLaravel\OpenApi;

use haddowg\JsonApi\OpenApi\ComponentNaming;
use haddowg\JsonApi\OpenApi\ErrorResponseProjector;
use haddowg\JsonApi\OpenApi\MediaType;
use haddowg\JsonApi\OpenApi\OpenApi;
use haddowg\JsonApi\OpenApi\Operation;
use haddowg\JsonApi\OpenApi\Paths;
use haddowg\JsonApi\OpenApi\Reference;
use haddowg\JsonApi\OpenApi\RequestBody;
use haddowg\JsonApi\OpenApi\Schema;
use haddowg\JsonApiLaravel\Action\ActionDescriptor;
use haddowg\JsonApiLaravel\Action\ActionInput;

/**
 * Lists an action's `accepts` media types under its operation's `requestBody.content`, in
 * declared order: a JSON:API document entry carries the input type's request schema, a raw
 * entry a binary string, and a no-input entry no schema. Such an action negotiates its
 * `Content-Type`, so the operation also documents the `415`. An action without `accepts`
 * keeps the request body core projects.
 *
 * @internal
 */
final class AcceptedMediaTypes
{
    /**
     * @param iterable<ActionDescriptor> $actions the actions the document covers
     */
    public static function apply(OpenApi $document, iterable $actions): OpenApi
    {
        $byOperationId = [];
        foreach ($actions as $action) {
            if ($action->accepts !== []) {
                $byOperationId['action.' . $action->type . '.' . $action->path] = $action;
            }
        }

        if ($byOperationId === [] || $document->paths === null) {
            return $document;
        }

        $paths = new Paths();
        foreach ($document->paths->items as $path => $item) {
            $operations = [];
            foreach ($item->operations as $method => $operation) {
                $action = $byOperationId[$operation->operationId ?? ''] ?? null;
                $operations[$method] = $action === null ? $operation : self::withAccepts($operation, $action, $document);
            }
            $paths = $paths->with($path, $item->withOperations($operations));
        }

        $document = $document->withPaths($paths);

        // Every operation that answered 415 before this was a raw one, so the shared
        // response component may be missing.
        $reference = ErrorResponseProjector::reference('415');
        $name = \substr($reference->ref, \strlen('#/components/responses/'));
        if (!isset($document->components->responses[$name])) {
            $byStatus = isset($document->components->schemas['ErrorDocument415']) ? [415 => []] : [];
            foreach (ErrorResponseProjector::components(['415'], $byStatus) as $component => $response) {
                $document = $document->withComponents($document->components->withResponse($component, $response));
            }
        }

        return $document;
    }

    private static function withAccepts(Operation $operation, ActionDescriptor $action, OpenApi $document): Operation
    {
        $content = [];
        foreach ($action->accepts as $mediaType => $mode) {
            $content[$mediaType] = match ($mode) {
                ActionInput::Document->name => MediaType::ofSchema(self::documentSchema($operation, $action, $document)),
                ActionInput::Raw->name => MediaType::ofSchema(Schema::ofType('string')->withFormat('binary')),
                default => new MediaType(),
            };
        }

        $body = $operation->requestBody instanceof RequestBody
            ? $operation->requestBody->withContent($content)
            : new RequestBody($content, 'The request body, in one of the accepted media types.', false);

        $operation = $operation->withRequestBody($body);
        if (!isset($operation->responses->responses['415'])) {
            $operation = $operation->withResponse('415', ErrorResponseProjector::reference('415'));
        }

        return $operation;
    }

    /**
     * The schema core gives a document-input action: reused when core projected one, else the
     * input type's create request, its resource object, or any object, as core picks it.
     */
    private static function documentSchema(Operation $operation, ActionDescriptor $action, OpenApi $document): Schema|Reference
    {
        $body = $operation->requestBody;
        $projected = $body instanceof RequestBody ? ($body->content[MediaType::JSON_API] ?? null) : null;
        if ($projected?->schema !== null) {
            return $projected->schema;
        }

        $base = ComponentNaming::base($action->inputType);
        foreach ([$base . 'CreateRequest', $base . 'Resource'] as $component) {
            if (isset($document->components->schemas[$component])) {
                return Schema::ref(ComponentNaming::schemaRef($component));
            }
        }

        return Schema::ofType('object');
    }
}
