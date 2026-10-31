<?php

declare(strict_types=1);

namespace haddowg\JsonApiLaravel\Attribute;

use haddowg\JsonApi\OpenApi\Metadata\AcceptedBody;
use haddowg\JsonApi\OpenApi\Metadata\ActionResource;
use haddowg\JsonApi\OpenApi\Metadata\ActionResponse;
use haddowg\JsonApi\OpenApi\Metadata\ActionResponses;
use haddowg\JsonApi\OpenApi\Metadata\OperationExample;
use haddowg\JsonApiLaravel\Action\ActionInput;
use haddowg\JsonApiLaravel\Action\ActionScope;

/**
 * Registers the annotated {@see \haddowg\JsonApiLaravel\Action\ActionHandlerInterface}
 * as a **custom, non-CRUD action** hanging off a JSON:API `type` under the reserved
 * `-actions` segment (the Laravel twin of the Symfony bundle's `#[AsJsonApiAction]`).
 * Discovery is by the same filesystem scan that finds resources: any
 * class carrying this attribute under a scanned path is registered as an action, no
 * `AbstractResource` sugar.
 *
 * `type` is the **mount type**: the `{uriType}` URL segment the action hangs off, and
 * the default serializer (response) + hydrator (request document) the action reuses.
 * `path` is the single `{action}` URL segment (one action name).
 *
 * `scope` chooses the URL shape: {@see ActionScope::Resource} (default) mounts
 * `POST /{uriType}/{id}/-actions/{path}` and resolves the `{id}` to an entity before the
 * handler runs; {@see ActionScope::Collection} mounts `POST /{uriType}/-actions/{path}`
 * with no id.
 *
 * `methods` is the author-declared HTTP method allow-list (default `['POST']`).
 *
 * `input` chooses the request-body contract: {@see ActionInput::None} (default) reads no
 * body; {@see ActionInput::Document} parses + validates + hydrates a JSON:API document
 * into an `inputType` instance; {@see ActionInput::Raw} relaxes request content-type
 * negotiation for a non-JSON:API upload. The request document is **decoupled from the
 * mount type**: it defaults to it, but `inputType` (Document mode only) may point at any
 * other registered type. A `null` `inputType` resolves to `type`.
 *
 * `responds` declares the action's success-response set the generated OpenAPI advertises,
 * as a list of the `new`-constructable atomic response objects (a single object is
 * accepted as shorthand). It defaults to a `200` document of the **mount type**
 * ({@see ActionResource}). The atomic responses:
 *  - {@see ActionResource}(`type`) — a `200` whose body is that type's resource document
 *    (the handler returns a {@see \haddowg\JsonApi\Response\DataResponse}).
 *  - {@see \haddowg\JsonApi\OpenApi\Metadata\MetaResult} — a `200` meta-only document
 *    ({@see \haddowg\JsonApi\Response\MetaResponse}).
 *  - {@see \haddowg\JsonApi\OpenApi\Metadata\NoContent} — a `204` ({@see \haddowg\JsonApi\Response\NoContentResponse}).
 *  - {@see \haddowg\JsonApi\OpenApi\Metadata\Accepted}(`jobType`) — a `202` accept whose
 *    body is the job type's document ({@see \haddowg\JsonApi\Response\AcceptedResponse}).
 *  - {@see \haddowg\JsonApi\OpenApi\Metadata\SeeOther} — a `303` completion redirect
 *    ({@see \haddowg\JsonApi\Response\SeeOtherResponse}).
 * The set is validated at declaration time ({@see ActionResponses::validate()}: non-empty,
 * unique status codes) and affects only the generated document — the runtime response is
 * whatever the handler returns. When a `200`-document {@see ActionResource} is declared,
 * its type is also the serializer every {@see \haddowg\JsonApi\Response\DataResponse} the
 * handler returns renders through; otherwise the mount type's serializer is used.
 *
 * `server` names the server this action is exposed on (a single server name, or `null`
 * for the implicit `default` server).
 *
 * `ability` is the Gate ability checked against the resolved entity (resource scope) /
 * the resource-class token (collection scope) via the package
 * {@see \haddowg\JsonApiLaravel\Authorization\Authorizer} before the handler runs — the
 * Laravel-native replacement for the bundle's Symfony ExpressionLanguage `security`
 * string. A `null` ability is an unsecured action (no gate). It rides
 * on the action, not the type.
 *
 * `name` is an optional route-name override.
 *
 * `tags` declares the OpenAPI tag names this action's operation is grouped under; an
 * empty array inherits the mount type's default tag.
 *
 * `asLink` exposes the action as a `links` member on every rendered resource of its mount
 * `type` — a host-owned, router-generated link merged out-of-band through core's
 * {@see \haddowg\JsonApi\Serializer\ResourceLinkContributorInterface} seam (keyed by the
 * action's `path`). It is **ability-aware**: when the action declares an `ability`, the
 * link renders only when the requester would pass that same gate. It is **resource-scope
 * only** — a collection-scope action with `asLink: true` is a declaration-time error.
 *
 * `summary` and `description` set the action operation's OpenAPI `summary` and
 * `description`; left `null`, the document keeps its generated defaults.
 *
 * `accepts` lists the request media types the action takes. A plain list entry is read in the
 * action's `input` mode; a `mediaType => ActionInput` entry carries its own mode, so one
 * action can take a JSON:API document and a CSV upload; an {@see AcceptedBody} list entry
 * also describes the body's schema for the OpenAPI document, and is read as a JSON:API
 * document when it is one and as a raw body otherwise. Wildcards such as `image/*` match,
 * but `text/plain`, `multipart/form-data` and `application/x-www-form-urlencoded` are
 * taken only when named. Left empty, the action takes what its `input` mode implies.
 *
 * `example` replaces or removes the example the OpenAPI document generates for the action's
 * operation: an {@see OperationExample} replaces the parts it names, `false` emits none, and
 * `null` keeps the generated one.
 *
 * `readOnly`, `destructive` and `idempotent` say how safe the action is to call, so a client
 * can tell a lookup from a write without guessing from the HTTP method. Each one left `null`
 * is undeclared; the declared ones project onto the action operation as one `x-safety`
 * object. An action cannot be both read-only and destructive; discovery rejects that pair.
 *
 * `extensions` are OpenAPI vendor extensions for the action's own operation, keyed by their
 * `x-` name. They merge over the mount type's `extensions`, and the action's value wins on a
 * shared key.
 *
 * `scopes` names the OAuth scopes every method of the action needs, overriding the
 * application's {@see \haddowg\JsonApi\Scope\OperationScopeResolverInterface}. `[]`
 * declares that it needs none, and `null` leaves the resolver to decide.
 */
#[\Attribute(\Attribute::TARGET_CLASS)]
final readonly class AsJsonApiAction
{
    /**
     * The declared success-response set (the OpenAPI projection reads it; a single-object
     * declaration is normalised to a one-element list, and an omitted declaration defaults
     * to a `200` document of the mount type).
     *
     * @var non-empty-list<ActionResponse>
     */
    public array $responds;

    /**
     * @param list<string>                             $methods  the author-declared HTTP method allow-list (default `['POST']`)
     * @param ActionResponse|list<ActionResponse>|null $responds the success-response set the document advertises (default: a `200` document of the mount type)
     * @param list<string>                             $tags     the OpenAPI tag names this action is grouped under (empty = inherit the mount type's default tag)
     * @param bool                                     $asLink   expose the action as an ability-aware `links` member on the mount type's resources (resource scope only)
     * @param string|null                              $summary     the operation's OpenAPI summary (null keeps the generated one)
     * @param string|null                              $description the operation's OpenAPI description (null keeps the generated one)
     * @param array<int|string, string|ActionInput|AcceptedBody> $accepts the accepted request media types: media types or {@see AcceptedBody} entries in a list, or `mediaType => ActionInput`
     * @param OperationExample|false|null                        $example the operation's OpenAPI example override: replaces the parts it names, `false` emits none, `null` keeps the generated one
     * @param bool|null                                          $readOnly    whether the action leaves server state unchanged (null leaves it undeclared)
     * @param bool|null                                          $destructive whether the action may change data in a way that cannot be undone (null leaves it undeclared)
     * @param bool|null                                          $idempotent  whether repeating the call with the same input has no further effect (null leaves it undeclared)
     * @param array<string, mixed>                               $extensions  OpenAPI vendor extensions for the action's operation, keyed by `x-` name
     * @param list<string>|null                                  $scopes      the OAuth scopes the action needs (`[]` for none, null to ask the scope resolver)
     */
    public function __construct(
        public string $type,
        public string $path,
        public array $methods = ['POST'],
        public ActionScope $scope = ActionScope::Resource,
        public ActionInput $input = ActionInput::None,
        public ?string $inputType = null,
        ActionResponse|array|null $responds = null,
        public ?string $server = null,
        public ?string $ability = null,
        public ?string $name = null,
        public array $tags = [],
        public bool $asLink = false,
        public ?string $summary = null,
        public ?string $description = null,
        public array $accepts = [],
        public OperationExample|false|null $example = null,
        public ?bool $readOnly = null,
        public ?bool $destructive = null,
        public ?bool $idempotent = null,
        public array $extensions = [],
        public ?array $scopes = null,
    ) {
        // Normalise the declaration: a single response object → a one-element list; an
        // omitted declaration → a `200` document of the mount type (the historical default).
        $normalized = $responds === null
            ? [new ActionResource($type)]
            : (\is_array($responds) ? \array_values($responds) : [$responds]);

        // Reject a contradictory set (empty, a non-ActionResponse, or duplicate status codes)
        // at declaration time — an action answers each status exactly one way.
        ActionResponses::validate($normalized);
        \assert($normalized !== []);
        $this->responds = $normalized;

        // A collection-scope action has no resource to hang a link on, so exposing it as
        // a resource link is incoherent — reject it at declaration time.
        if ($asLink && $scope === ActionScope::Collection) {
            throw new \LogicException(\sprintf(
                'The JSON:API action "%s" on type "%s" declares asLink with a Collection scope; a resource link '
                . 'requires a resource to hang off, so asLink is supported only for a Resource-scope action.',
                $path,
                $type,
            ));
        }

        // The path is interpolated verbatim as the single `{action}` URL segment, so it must
        // be one literal segment: a `/` would mint extra segments and a `{…}` would turn it
        // into a live route parameter matching arbitrary action names. Reject anything but a
        // single conservative segment at declaration time.
        if (\preg_match('/^[A-Za-z0-9._-]+$/', $path) !== 1) {
            throw new \LogicException(\sprintf(
                'The JSON:API action path "%s" on type "%s" is not a single literal URL segment; it must match '
                . '/^[A-Za-z0-9._-]+$/ (no slashes, no route placeholders) so it maps to exactly one action name.',
                $path,
                $type,
            ));
        }

        // An empty methods list registers an unreachable route; a junk verb propagates to the
        // router. Require a non-empty list of known HTTP verbs.
        $knownVerbs = ['GET', 'HEAD', 'POST', 'PUT', 'PATCH', 'DELETE', 'OPTIONS'];
        if ($methods === []) {
            throw new \LogicException(\sprintf(
                'The JSON:API action "%s" on type "%s" declares an empty methods list; declare at least one HTTP '
                . 'verb (default ["POST"]).',
                $path,
                $type,
            ));
        }
        foreach ($methods as $method) {
            if (!\in_array(\strtoupper($method), $knownVerbs, true)) {
                throw new \LogicException(\sprintf(
                    'The JSON:API action "%s" on type "%s" declares an unknown HTTP method "%s"; allowed verbs are %s.',
                    $path,
                    $type,
                    $method,
                    \implode(', ', $knownVerbs),
                ));
            }
        }

        VendorExtensions::assertMap(\sprintf('AsJsonApiAction "%s" on type "%s"', $path, $type), $extensions, 'extensions');

        if ($scopes !== null) {
            OperationScopes::assertList(\sprintf('AsJsonApiAction "%s" on type "%s" scopes', $path, $type), $scopes);
        }

        foreach ($accepts as $key => $value) {
            $valid = \is_string($key)
                ? $value instanceof ActionInput && self::isMediaType($key)
                : $value instanceof AcceptedBody || (\is_string($value) && self::isMediaType($value));
            if (!$valid) {
                throw new \LogicException(\sprintf(
                    'The JSON:API action "%s" on type "%s" declares an invalid accepts entry; use a list of media '
                    . 'types or AcceptedBody entries, or a map of media type to ActionInput.',
                    $path,
                    $type,
                ));
            }
        }
    }

    /**
     * The `accepts` declaration as core's {@see AcceptedBody} list, in declared order: a
     * plain media type has no schema, and a `Document` map entry is a JSON:API document.
     *
     * @return list<AcceptedBody>
     */
    public function acceptedBodies(): array
    {
        $bodies = [];
        foreach ($this->accepts as $key => $value) {
            $body = match (true) {
                $value instanceof AcceptedBody => $value,
                \is_string($key) && $value === ActionInput::Document => new AcceptedBody($key, document: true),
                \is_string($key) => new AcceptedBody($key),
                \is_string($value) => new AcceptedBody($value),
                default => null,
            };
            if ($body !== null) {
                $bodies[] = $body;
            }
        }

        return $bodies;
    }

    /**
     * The input mode each accepted media type is read in, keyed by the media type lowercased
     * without parameters, the form core's content-type matcher reports.
     *
     * @return array<string, ActionInput>
     */
    public function acceptedInputs(): array
    {
        $inputs = [];
        foreach ($this->accepts as $key => $value) {
            $body = $value instanceof AcceptedBody ? $value : null;
            $declared = \is_string($key) ? $key : (\is_string($value) ? $value : '');
            $mediaType = $body?->essence() ?? \strtolower(\trim(\explode(';', $declared)[0]));
            $inputs[$mediaType] = match (true) {
                $value instanceof ActionInput => $value,
                $body === null => $this->input,
                $body->document => ActionInput::Document,
                default => $this->input === ActionInput::None ? ActionInput::None : ActionInput::Raw,
            };
        }

        return $inputs;
    }

    private static function isMediaType(string $mediaType): bool
    {
        return \preg_match('#^[a-z0-9!\#$&^_.+*-]+/[a-z0-9!\#$&^_.+*-]+$#i', \trim(\explode(';', $mediaType)[0])) === 1;
    }
}
