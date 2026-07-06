<?php

declare(strict_types=1);

namespace haddowg\JsonApiLaravel\Action;

use haddowg\JsonApi\Response\AcceptedResponse;
use haddowg\JsonApi\Response\DataResponse;
use haddowg\JsonApi\Response\ErrorResponse;
use haddowg\JsonApi\Response\MetaResponse;
use haddowg\JsonApi\Response\NoContentResponse;
use haddowg\JsonApi\Response\SeeOtherResponse;

/**
 * A custom, non-CRUD action (the Laravel twin of the bundle's
 * `Action\ActionHandlerInterface`). An author implements this on a standalone handler
 * class declared with {@see \haddowg\JsonApiLaravel\Attribute\AsJsonApiAction} — no
 * `AbstractResource` sugar — and it is discovered by the filesystem scan exactly like a
 * resource.
 *
 * The handler receives the resolved {@see ActionContext} — the entity (resource scope),
 * the hydrated input (Document mode), the request, the query parameters, the resolving
 * server and the `outputType` serializer plus response conveniences
 * ({@see ActionContext::data()}/{@see ActionContext::meta()}/{@see ActionContext::noContent()})
 * — and returns a **core response value object** (no raw response): a
 * {@see DataResponse}/{@see MetaResponse} renders through the `outputType` serializer via
 * the shared render path, a {@see NoContentResponse} yields a bodyless `204`, an
 * {@see ErrorResponse} renders a JSON:API error document.
 */
interface ActionHandlerInterface
{
    public function handle(ActionContext $context): DataResponse|MetaResponse|NoContentResponse|AcceptedResponse|SeeOtherResponse|ErrorResponse;
}
