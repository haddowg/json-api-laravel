<?php

declare(strict_types=1);

namespace haddowg\JsonApiLaravel\Attribute;

use haddowg\JsonApi\OpenApi\Metadata\ActionResource;
use haddowg\JsonApi\OpenApi\Metadata\ActionResponse;
use haddowg\JsonApi\OpenApi\Metadata\ActionResponses;
use haddowg\JsonApiLaravel\Action\ActionInput;
use haddowg\JsonApiLaravel\Action\ActionScope;

/**
 * Registers the annotated {@see \haddowg\JsonApiLaravel\Action\ActionHandlerInterface}
 * as a **custom, non-CRUD action** hanging off a JSON:API `type` under the reserved
 * `-actions` segment (the Laravel twin of the Symfony bundle's `#[AsJsonApiAction]`,
 * PLAN decision 12). Discovery is by the same filesystem scan that finds resources: any
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
 * string (PLAN decision 12). A `null` ability is an unsecured action (no gate). It rides
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
    }
}
