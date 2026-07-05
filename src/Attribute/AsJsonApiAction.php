<?php

declare(strict_types=1);

namespace haddowg\JsonApiLaravel\Attribute;

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
 * negotiation for a non-JSON:API upload.
 *
 * The request and response documents are **decoupled from the mount type**: both default
 * to it, but `inputType` (Document mode only) and `outputType` may point at any other
 * registered type. A `null` `inputType`/`outputType` resolves to `type`.
 *
 * `returns204` declares the action returns **no response body** (a `204 No Content`);
 * `outputMeta` declares a **meta-only document**. Each suppresses the `outputType` default
 * in the generated OpenAPI document; they are mutually exclusive with each other and with
 * an explicit `outputType`. They affect only the generated document — the runtime response
 * is whatever the handler returns.
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
     * @param list<string> $methods    the author-declared HTTP method allow-list (default `['POST']`)
     * @param bool         $returns204 the action returns no response body (the document advertises `204`); mutually exclusive with `outputType` and `outputMeta`
     * @param bool         $outputMeta the action returns a meta-only document; mutually exclusive with `outputType` and `returns204`
     * @param list<string> $tags       the OpenAPI tag names this action is grouped under (empty = inherit the mount type's default tag)
     * @param bool         $asLink     expose the action as an ability-aware `links` member on the mount type's resources (resource scope only)
     */
    public function __construct(
        public string $type,
        public string $path,
        public array $methods = ['POST'],
        public ActionScope $scope = ActionScope::Resource,
        public ActionInput $input = ActionInput::None,
        public ?string $inputType = null,
        public ?string $outputType = null,
        public bool $returns204 = false,
        public bool $outputMeta = false,
        public ?string $server = null,
        public ?string $ability = null,
        public ?string $name = null,
        public array $tags = [],
        public bool $asLink = false,
    ) {
        // An action answers exactly one way. A `204` and a meta-only document are both
        // body-shape declarations that suppress the `outputType` default, so declaring
        // both — or either alongside an explicit `outputType` — is contradictory.
        if ($returns204 && $outputMeta) {
            throw new \LogicException(\sprintf(
                'The JSON:API action "%s" on type "%s" declares both returns204 and outputMeta; an action answers '
                . 'exactly one way, so they are mutually exclusive.',
                $path,
                $type,
            ));
        }

        if ($outputMeta && $outputType !== null) {
            throw new \LogicException(\sprintf(
                'The JSON:API action "%s" on type "%s" declares both outputMeta and an outputType; a meta-only '
                . 'document carries no resource, so they are mutually exclusive.',
                $path,
                $type,
            ));
        }

        if ($returns204 && $outputType !== null) {
            throw new \LogicException(\sprintf(
                'The JSON:API action "%s" on type "%s" declares both returns204 and an outputType; a `204` response '
                . 'carries no body, so they are mutually exclusive.',
                $path,
                $type,
            ));
        }

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
