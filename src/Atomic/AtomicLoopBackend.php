<?php

declare(strict_types=1);

namespace haddowg\JsonApiLaravel\Atomic;

use haddowg\JsonApi\Atomic\AtomicLoopBackendInterface;
use haddowg\JsonApi\Atomic\AtomicOperationCode;
use haddowg\JsonApi\Atomic\AtomicResult;
use haddowg\JsonApi\Atomic\LocalIdRegistry;
use haddowg\JsonApi\Atomic\OperationDescriptor;
use haddowg\JsonApi\Atomic\Ref;
use haddowg\JsonApi\Exception\AtomicOperationsInvalid;
use haddowg\JsonApi\Operation\AddToRelationshipOperation;
use haddowg\JsonApi\Operation\CreateResourceOperation;
use haddowg\JsonApi\Operation\DeleteResourceOperation;
use haddowg\JsonApi\Operation\JsonApiOperationInterface;
use haddowg\JsonApi\Operation\OperationContext;
use haddowg\JsonApi\Operation\QueryParameters;
use haddowg\JsonApi\Operation\RemoveFromRelationshipOperation;
use haddowg\JsonApi\Operation\Target;
use haddowg\JsonApi\Operation\UpdateRelationshipOperation;
use haddowg\JsonApi\Operation\UpdateResourceOperation;
use haddowg\JsonApi\Request\JsonApiRequestInterface;
use haddowg\JsonApi\Response\AbstractResponse;
use haddowg\JsonApi\Response\ErrorResponse;
use haddowg\JsonApi\Response\NoContentResponse;
use haddowg\JsonApi\Server\Server;
use haddowg\JsonApiLaravel\DataPersister\DataPersisterRegistry;
use haddowg\JsonApiLaravel\DataPersister\TransactionalDataPersisterInterface;
use haddowg\JsonApiLaravel\DataPersister\WriteTransactionContext;
use haddowg\JsonApiLaravel\Operation\CrudOperationHandler;
use haddowg\JsonApiLaravel\Operation\Operation;
use Illuminate\Http\Request;
use Illuminate\Routing\Router;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;
use Symfony\Component\HttpKernel\Exception\MethodNotAllowedHttpException;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

/**
 * The package's Atomic Operations executor — the storage- and Laravel-specific
 * {@see AtomicLoopBackendInterface} the framework-agnostic {@see \haddowg\JsonApi\Atomic\AtomicLoop}
 * drives once per `POST /operations` batch (the Laravel twin of the bundle's
 * `Atomic\AtomicLoopBackend`).
 *
 * It turns each parsed {@see OperationDescriptor} into the matching core CRUD operation VO
 * and dispatches it through the package's own {@see CrudOperationHandler} **in-process** —
 * calling `handle()` directly, NOT `Server::dispatch()`, so serving fires once for the
 * whole batch (not per sub-operation) and the per-op After* lifecycle hooks defer to
 * post-commit via the shared {@see WriteTransactionContext}. The whole batch runs inside
 * one transaction opened on every participating persister, committed together on success or
 * rolled back together on the first failure.
 *
 * **Decoration is batch-scoped.** A handler decorator wraps the batch as a whole (it sees
 * the single {@see \haddowg\JsonApi\Operation\AtomicOperationsOperation}), NOT each
 * sub-operation: the sub-operations re-enter the same handler instance this backend was
 * constructed with, bypassing any decorator chain.
 *
 * **Local-id rewrite + registration.** Before dispatch, every `{type, lid}` the operation
 * references — in its `ref` and in any linkage inside its `data` — is rewritten to
 * `{type, id}` by resolving the `lid` through the shared {@see LocalIdRegistry} (a miss
 * throws {@see \haddowg\JsonApi\Exception\LocalIdNotFound}). After an `add` of a resource
 * whose `data` carried a `lid`, `(type, lid) → assigned-id` is registered so a later
 * operation can reference it (a duplicate throws
 * {@see \haddowg\JsonApi\Exception\LocalIdConflict}).
 *
 * **Pre-flight.** Before opening anything, two refusals run: (1) every sub-operation must
 * target a CRUD operation its type exposes on the HTTP surface — the same per-type allow-list
 * the router gates direct calls with — else the batch is refused with
 * {@see AtomicOperationNotExposed} (`403`), so a `readOnly` type cannot be written via the
 * batch; (2) every participating type's persister is resolved and must implement
 * {@see TransactionalDataPersisterInterface}, else the batch is refused with
 * {@see AtomicOperationsNotSupported} (`403`) before a single write — and our Eloquent /
 * in-memory reference persisters both nest as savepoints, so a single shared persister
 * begins/commits/rolls back once (the genuinely-atomic single-persister case).
 */
final class AtomicLoopBackend implements AtomicLoopBackendInterface
{
    /**
     * The participating transactional persisters, keyed by object hash so the same
     * persister (a shared Eloquent fallback across every type) begins / commits / rolls
     * back exactly once.
     *
     * @var array<string, TransactionalDataPersisterInterface>
     */
    private array $transactional;

    private bool $opened = false;

    private readonly LoggerInterface $logger;

    /**
     * @param list<OperationDescriptor>   $descriptors        the parsed batch, in request order
     * @param array<string, list<string>> $exposedOperations  the per-type exposed CRUD operation allow-list ({@see \haddowg\JsonApiLaravel\Operation\Operation} case values), keyed by JSON:API type — the same allow-list the router gates the direct HTTP surface with; a type absent from the map exposes none
     */
    public function __construct(
        private readonly array $descriptors,
        private readonly Server $server,
        private readonly JsonApiRequestInterface $request,
        private readonly CrudOperationHandler $handler,
        private readonly DataPersisterRegistry $persisters,
        private readonly WriteTransactionContext $context,
        private readonly Router $router,
        private readonly array $exposedOperations = [],
        ?LoggerInterface $logger = null,
    ) {
        $this->logger = $logger ?? new NullLogger();

        // Pre-flight (before begin() opens anything): refuse the batch if ANY sub-operation
        // would write a type in a way its HTTP surface forbids (the per-type operation
        // allow-list the router gates direct calls with), then resolve every participating
        // type's persister and refuse if ANY is not transactional.
        $this->guardExposedOperations();
        $this->transactional = $this->collectTransactionalPersisters();
    }

    public function begin(): void
    {
        // Open EVERY participating transaction FIRST — before activating the deferred-hook
        // context. Core's AtomicLoop::run() calls begin() OUTSIDE its try/catch, so if any
        // begin throws, roll back the ones already opened and rethrow — leaving nothing open
        // and the context inactive.
        $opened = [];
        try {
            foreach ($this->transactional as $hash => $persister) {
                $persister->beginTransaction();
                $opened[$hash] = $persister;
            }
        } catch (\Throwable $throwable) {
            foreach ($opened as $persister) {
                $persister->rollback();
            }

            throw $throwable;
        }

        $this->context->activate();
        $this->opened = true;
    }

    public function executeOne(OperationDescriptor $op, LocalIdRegistry $lids): AtomicResult
    {
        // (a) rewrite the `{type, lid}` the operation's `ref` references to `{type, id}`.
        $ref = $op->ref !== null ? $this->resolveRefLid($op->ref, $lids) : null;

        // (b) resolve the target — from ref, by matching href, or (a resource add) from
        // `data.type`.
        $target = match (true) {
            $ref !== null => $this->targetFromRef($ref),
            $op->href !== null => $this->targetFromHref($op->href),
            default => new Target($this->createTypeFromData($op->data)),
        };

        // (b') rewrite every `{type, lid}` the operation's `data` references — driven by the
        // target's relationship-ness (a relationship endpoint's data IS linkage; a resource
        // endpoint's data has references only inside `relationships[*]`).
        $data = $this->resolveDataLids($op->data, $target->hasRelationship(), $lids);

        // (c)/(d) build the matching core CRUD operation VO off a synthetic per-op request
        // and dispatch it through the handler IN-PROCESS (handle(), never Server::dispatch).
        $operation = $this->buildOperation($op->opCode, $target, $data);
        $response = $this->handler->handle($operation);

        // A sub-operation that resolved to a 404/400 returns an ErrorResponse rather than
        // throwing; surface it as the throw the loop expects so the whole batch rolls back.
        if ($response instanceof ErrorResponse) {
            throw new SubOperationFailed($response, $this->server, $this->request);
        }

        // (f) a NoContentResponse is the empty result object; everything else renders to its
        // `{data?, meta?}` fragment once (reused for both the lid registration and the result).
        if ($response instanceof NoContentResponse) {
            return AtomicResult::empty();
        }
        $fragment = $this->fragmentOf($response);

        // (e) after an add that created a resource, register (type, lid) → assigned id.
        if ($op->opCode === AtomicOperationCode::Add) {
            $this->registerCreatedLid($target->type, $data, $fragment, $lids);
        }

        return AtomicResult::fromDocument($fragment);
    }

    public function commit(): void
    {
        // Commit each participating persister in turn. With a SINGLE persister this is
        // genuinely atomic; with MULTIPLE distinct persisters there is no two-phase commit,
        // so a later commit failure rolls back the not-yet-committed ones and re-raises.
        $committed = [];
        try {
            foreach ($this->transactional as $hash => $persister) {
                $persister->commit();
                $committed[$hash] = true;
            }
        } catch (\Throwable $throwable) {
            foreach ($this->transactional as $hash => $persister) {
                if (!isset($committed[$hash])) {
                    $persister->rollback();
                }
            }

            throw $throwable;
        }

        // The data is durable: drain the deferred After* hooks (FIFO), best-effort — a
        // throwing hook must NOT turn a durably-committed batch into a failure, so each
        // exception is caught + logged and the remaining hooks still run. The context is
        // ALWAYS deactivated.
        try {
            $this->context->drain(function (\Throwable $throwable): void {
                $this->logger->error(
                    'A deferred After* lifecycle hook threw after an Atomic Operations batch committed; '
                    . 'the batch already succeeded, so the error is logged and ignored.',
                    ['exception' => $throwable],
                );
            });
        } finally {
            $this->context->deactivate();
        }
    }

    public function rollback(): void
    {
        // Guarded: roll back only the transactions begin() actually opened; each persister's
        // own rollback() is a safe no-op when already closed, so a commit-failure rollback
        // never throws a secondary error that masks the original.
        if ($this->opened) {
            foreach ($this->transactional as $persister) {
                $persister->rollback();
            }
            $this->opened = false;
        }

        // ALWAYS discard the deferred queue and deactivate — a rolled-back batch fires no
        // After* hooks, and the context must never stay active.
        $this->context->deactivate();
    }

    /**
     * Refuses the whole batch, in pre-flight, when any sub-operation would perform a CRUD
     * operation its target type does not expose on the HTTP surface — the allow-list the
     * router gates direct calls with. Atomic sub-operations never touch the router, so
     * without this a `readOnly` type (fetch-only) would be writable via `POST /operations`.
     *
     * Each descriptor maps to the operation the router would gate the equivalent direct call
     * on: a resource `add`→Create / `update`→Update / `remove`→Delete, and every relationship
     * mutation → the parent's Update (the router emits the relationship-mutation routes only
     * for an Update-exposed type). A target type absent from the map declares no resource, so
     * it exposes nothing and every write is refused.
     *
     * @throws AtomicOperationNotExposed
     * @throws AtomicHrefUnresolvable
     */
    private function guardExposedOperations(): void
    {
        foreach ($this->descriptors as $descriptor) {
            [$type, $targetsRelationship] = $this->targetShapeFor($descriptor);

            // A type with no discovered resource has no HTTP CRUD routes at all; its atomic
            // writability is governed solely by persister registration (the pre-flight
            // persister scan → a `404` when none), not by an operation allow-list, so it is
            // not gated here. A type WITH a resource is gated against its exposed operations
            // exactly as the router gates the equivalent direct call.
            $exposed = $this->exposedOperations[$type] ?? null;
            if ($exposed === null) {
                continue;
            }

            $required = $this->requiredOperation($descriptor->opCode, $targetsRelationship);
            if (!\in_array($required->value, $exposed, true)) {
                throw new AtomicOperationNotExposed($type, $required->value);
            }
        }
    }

    /**
     * The (type, targets-a-relationship) shape of a descriptor's target, resolved without
     * its lids (the type + relationship-ness do not depend on lid values): a `ref` carries
     * both; an `href` is matched against the router; a resource `add` reads its `data.type`
     * and never targets a relationship.
     *
     * @return array{0: string, 1: bool}
     */
    private function targetShapeFor(OperationDescriptor $descriptor): array
    {
        if ($descriptor->ref !== null) {
            return [$descriptor->ref->type, $descriptor->ref->relationship !== null];
        }

        if ($descriptor->href !== null) {
            $match = $this->matchHref($descriptor->href);
            $type = $match['_jsonapi_type'] ?? null;
            if (!\is_string($type) || $type === '') {
                throw new AtomicHrefUnresolvable($descriptor->href);
            }
            $relationship = $match['relationship'] ?? null;

            return [$type, \is_string($relationship) && $relationship !== ''];
        }

        return [$this->createTypeFromData($descriptor->data), false];
    }

    /**
     * The CRUD operation the router would gate the equivalent direct call on.
     */
    private function requiredOperation(AtomicOperationCode $opCode, bool $targetsRelationship): Operation
    {
        if ($targetsRelationship) {
            return Operation::Update;
        }

        return match ($opCode) {
            AtomicOperationCode::Add => Operation::Create,
            AtomicOperationCode::Update => Operation::Update,
            AtomicOperationCode::Remove => Operation::Delete,
        };
    }

    /**
     * Resolves the persister of every participating type and returns the transactional
     * ones, keyed by object hash. Refuses the whole batch up front when a participating type
     * has no registered persister ({@see AtomicTargetTypeUnknown}, `404`) or a persister
     * that is not transactional ({@see AtomicOperationsNotSupported}, `403`).
     *
     * @return array<string, TransactionalDataPersisterInterface>
     *
     * @throws AtomicTargetTypeUnknown
     * @throws AtomicOperationsNotSupported
     */
    private function collectTransactionalPersisters(): array
    {
        $transactional = [];
        foreach ($this->participatingTypes() as $type) {
            if (!$this->persisters->supportsType($type)) {
                throw new AtomicTargetTypeUnknown($type);
            }

            $persister = $this->persisters->forType($type);
            if (!$persister instanceof TransactionalDataPersisterInterface) {
                throw new AtomicOperationsNotSupported($type);
            }

            $transactional[\spl_object_hash($persister)] = $persister;
        }

        return $transactional;
    }

    /**
     * The distinct primary types the batch writes to, resolved from each operation's target
     * (a `ref` carries its `type`; an `href` is matched against the router; a resource
     * `add` with neither reads its `data.type`).
     *
     * @return list<string>
     */
    private function participatingTypes(): array
    {
        $types = [];
        foreach ($this->descriptors as $descriptor) {
            $type = match (true) {
                $descriptor->ref !== null => $descriptor->ref->type,
                $descriptor->href !== null => $this->typeFromHref($descriptor->href),
                default => $this->createTypeFromData($descriptor->data),
            };

            $types[$type] = true;
        }

        return \array_keys($types);
    }

    /**
     * The `data.type` of a resource `add` that targets its endpoint neither by `ref` nor
     * `href` — the create's only target source.
     */
    private function createTypeFromData(mixed $data): string
    {
        if (\is_array($data) && isset($data['type']) && \is_string($data['type']) && $data['type'] !== '') {
            return $data['type'];
        }

        throw new AtomicOperationsInvalid(
            "An atomic 'add' with no 'ref'/'href' must carry a resource object with a 'type'.",
            '',
        );
    }

    /**
     * Builds the core CRUD operation VO for the descriptor's code and resolved target,
     * carrying a synthetic per-operation request whose parsed body is `{data: <data>}`.
     */
    private function buildOperation(AtomicOperationCode $opCode, Target $target, mixed $data): JsonApiOperationInterface
    {
        $query = new QueryParameters([], [], [], [], []);

        // The verb is load-bearing: an update MUST be PATCH so its `data.id` is the target,
        // not a rejected client-generated create id. A relationship operation's verb maps
        // add=POST / replace=PATCH / remove=DELETE.
        $method = match ($opCode) {
            AtomicOperationCode::Add => 'POST',
            AtomicOperationCode::Update => 'PATCH',
            AtomicOperationCode::Remove => 'DELETE',
        };

        if ($target->hasRelationship()) {
            $body = $this->subRequest($target, $method, $data);
            $context = new OperationContext($this->server, $body);

            return match ($opCode) {
                AtomicOperationCode::Add => new AddToRelationshipOperation($target, $query, $context, $body),
                AtomicOperationCode::Update => new UpdateRelationshipOperation($target, $query, $context, $body),
                AtomicOperationCode::Remove => new RemoveFromRelationshipOperation($target, $query, $context, $body),
            };
        }

        $body = $this->subRequest($target, $method, $data);
        $context = new OperationContext($this->server, $body);

        return match ($opCode) {
            AtomicOperationCode::Add => new CreateResourceOperation($target, $query, $context, $body),
            AtomicOperationCode::Update => new UpdateResourceOperation($target, $query, $context, $body),
            AtomicOperationCode::Remove => new DeleteResourceOperation($target, $query, $context),
        };
    }

    /**
     * A synthetic per-operation request derived from the batch request: the same
     * headers/attributes with the method + URI rewritten to the sub-operation's verb/path
     * and a parsed body of `{data: <data>}`.
     */
    private function subRequest(Target $target, string $method, mixed $data): JsonApiRequestInterface
    {
        $uri = $this->request->getUri()->withPath($this->pathFor($target));

        $request = $this->request
            ->withMethod($method)
            ->withUri($uri, preserveHost: true)
            ->withParsedBody(['data' => $data]);
        \assert($request instanceof JsonApiRequestInterface);

        return $request;
    }

    /**
     * The URL path a sub-operation addresses (its self link / route), built from the
     * resolved target.
     */
    private function pathFor(Target $target): string
    {
        $path = '/' . $target->type;
        if ($target->id !== null) {
            $path .= '/' . $target->id;
        }
        if ($target->relationship !== null) {
            $path .= ($target->isRelationshipEndpoint ? '/relationships/' : '/') . $target->relationship;
        }

        return $path;
    }

    /**
     * Builds the {@see Target} from a (lid-resolved) {@see Ref}: a relationship ref targets
     * the relationship-linkage endpoint.
     */
    private function targetFromRef(Ref $ref): Target
    {
        return new Target(
            $ref->type,
            $ref->id,
            $ref->relationship,
            isRelationshipEndpoint: $ref->relationship !== null,
        );
    }

    /**
     * Builds the {@see Target} from an `href` by matching it against Laravel's router: the
     * matched route's `_jsonapi_*` defaults give the type, id, relationship name, and
     * whether it is the relationship-linkage endpoint.
     */
    private function targetFromHref(string $href): Target
    {
        $match = $this->matchHref($href);

        $type = $match['_jsonapi_type'] ?? null;
        if (!\is_string($type) || $type === '') {
            throw new AtomicHrefUnresolvable($href);
        }

        $id = $match['id'] ?? null;
        $relationship = $match['relationship'] ?? null;

        return new Target(
            $type,
            \is_string($id) ? $id : null,
            \is_string($relationship) && $relationship !== '' ? $relationship : null,
            isRelationshipEndpoint: ($match['_jsonapi_relationship_endpoint'] ?? null) === true,
        );
    }

    /**
     * The primary `_jsonapi_type` an `href` resolves to, for the pre-flight persister scan.
     */
    private function typeFromHref(string $href): string
    {
        $type = $this->matchHref($href)['_jsonapi_type'] ?? null;
        if (!\is_string($type) || $type === '') {
            throw new AtomicHrefUnresolvable($href);
        }

        return $type;
    }

    /**
     * Matches an `href` (its path) against Laravel's router and returns the matched route's
     * defaults + path parameters (`_jsonapi_type`, `id`, `relationship`,
     * `_jsonapi_relationship_endpoint`), or throws {@see AtomicHrefUnresolvable} when it
     * matches no route.
     *
     * The match is **method-neutral**: every addressable JSON:API path has a GET route (a
     * resource/collection/related/relationship read), so a synthetic GET request resolves
     * the route defaults regardless of the operation's own verb.
     *
     * @return array<string, mixed>
     */
    private function matchHref(string $href): array
    {
        $path = (string) \parse_url($href, \PHP_URL_PATH);
        if ($path === '') {
            $path = $href;
        }

        try {
            $route = $this->router->getRoutes()->match(Request::create($path, 'GET'));
        } catch (NotFoundHttpException | MethodNotAllowedHttpException) {
            throw new AtomicHrefUnresolvable($href);
        }

        /** @var array<string, mixed> $defaults */
        $defaults = $route->defaults;

        return [
            '_jsonapi_type' => $defaults['_jsonapi_type'] ?? null,
            '_jsonapi_relationship_endpoint' => $defaults['_jsonapi_relationship_endpoint'] ?? null,
            'id' => $route->parameter('id'),
            'relationship' => $route->parameter('relationship'),
        ];
    }

    /**
     * Resolves a {@see Ref}'s `lid` to a server `id` via the registry (a `ref` with an `id`
     * already, or with no `lid`, is returned unchanged). A miss throws
     * {@see \haddowg\JsonApi\Exception\LocalIdNotFound}.
     */
    private function resolveRefLid(Ref $ref, LocalIdRegistry $lids): Ref
    {
        if ($ref->lid === null) {
            return $ref;
        }

        return new Ref($ref->type, $lids->resolve($ref->type, $ref->lid), null, $ref->relationship);
    }

    /**
     * Rewrites every `{type, lid}` resource-identifier in the operation's `data` to
     * `{type, id}` via the registry, driven by the target (a relationship endpoint's `data`
     * IS linkage; a resource endpoint's `data` has references only inside its
     * `relationships[*]`; the resource object's own top-level `lid` is never resolved here).
     */
    private function resolveDataLids(mixed $data, bool $targetsRelationship, LocalIdRegistry $lids): mixed
    {
        if (!\is_array($data)) {
            return $data;
        }

        if ($targetsRelationship) {
            if (\array_is_list($data)) {
                return \array_map(fn(mixed $item): mixed => $this->resolveIdentifierLid($item, $lids), $data);
            }

            return $this->resolveIdentifierLid($data, $lids);
        }

        if (\array_key_exists('relationships', $data) && \is_array($data['relationships'])) {
            $data['relationships'] = $this->resolveRelationshipsLids($data['relationships'], $lids);
        }

        return $data;
    }

    /**
     * Resolves the lids in each named relationship's linkage of a resource object's
     * `relationships` member.
     *
     * @param array<array-key, mixed> $relationships
     *
     * @return array<array-key, mixed>
     */
    private function resolveRelationshipsLids(array $relationships, LocalIdRegistry $lids): array
    {
        foreach ($relationships as $name => $relationship) {
            if (!\is_array($relationship) || !\array_key_exists('data', $relationship)) {
                continue;
            }

            $linkage = $relationship['data'];
            if (\is_array($linkage) && \array_is_list($linkage)) {
                $relationship['data'] = \array_map(fn(mixed $item): mixed => $this->resolveIdentifierLid($item, $lids), $linkage);
            } elseif (\is_array($linkage)) {
                $relationship['data'] = $this->resolveIdentifierLid($linkage, $lids);
            }

            $relationships[$name] = $relationship;
        }

        return $relationships;
    }

    /**
     * Rewrites one resource-identifier carrying a `lid` to one carrying the resolved `id`
     * (dropping the `lid`, preserving `meta`), leaving an identifier that already names an
     * `id` — or any non-identifier value — untouched. A miss throws
     * {@see \haddowg\JsonApi\Exception\LocalIdNotFound}.
     */
    private function resolveIdentifierLid(mixed $identifier, LocalIdRegistry $lids): mixed
    {
        if (!\is_array($identifier) || !isset($identifier['lid']) || !\is_string($identifier['lid'])) {
            return $identifier;
        }

        $type = $identifier['type'] ?? null;
        if (!\is_string($type)) {
            return $identifier;
        }

        $identifier['id'] = $lids->resolve($type, $identifier['lid']);
        unset($identifier['lid']);

        return $identifier;
    }

    /**
     * Registers `(type, lid) → assigned id` after an `add` created a resource whose `data`
     * carried a `lid`, reading the assigned id off the rendered result fragment. A duplicate
     * `(type, lid)` throws {@see \haddowg\JsonApi\Exception\LocalIdConflict}.
     *
     * @param array<string, mixed> $fragment the rendered `{data?, meta?}` result fragment
     */
    private function registerCreatedLid(string $type, mixed $data, array $fragment, LocalIdRegistry $lids): void
    {
        if (!\is_array($data) || !isset($data['lid']) || !\is_string($data['lid'])) {
            return;
        }

        $resource = $fragment['data'] ?? null;
        $id = \is_array($resource) ? ($resource['id'] ?? null) : null;
        if (!\is_string($id)) {
            return;
        }

        $lids->register($type, $data['lid'], $id);
    }

    /**
     * Renders a response value object to its `{data?, meta?}` result-object fragment — the
     * only members the extension allows in a result object (never `links`/`included`/`jsonapi`).
     *
     * @return array<string, mixed>
     */
    private function fragmentOf(object $response): array
    {
        \assert($response instanceof AbstractResponse);

        $psr = $response->toPsrResponse($this->server, $this->request);
        $body = (string) $psr->getBody();
        if ($body === '') {
            return [];
        }

        /** @var array<string, mixed> $decoded */
        $decoded = \json_decode($body, true, 512, \JSON_THROW_ON_ERROR);

        $fragment = [];
        if (\array_key_exists('data', $decoded)) {
            $fragment['data'] = $decoded['data'];
        }
        if (\array_key_exists('meta', $decoded)) {
            $fragment['meta'] = $decoded['meta'];
        }

        return $fragment;
    }
}
