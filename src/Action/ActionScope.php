<?php

declare(strict_types=1);

namespace haddowg\JsonApiLaravel\Action;

/**
 * The URL scope a custom action (`#[AsJsonApiAction]`) hangs off — the Laravel twin of
 * the Symfony bundle's `Action\ActionScope`.
 *
 * - {@see Resource}: `POST /{uriType}/{id}/-actions/{action}` — the `{id}` is resolved
 *   to an entity (via the type's {@see \haddowg\JsonApiLaravel\DataProvider\DataProviderInterface})
 *   before the handler runs; {@see ActionContext::entity()} returns it.
 * - {@see Collection}: `POST /{uriType}/-actions/{action}` — no id;
 *   {@see ActionContext::entity()} is `null`.
 */
enum ActionScope
{
    case Resource;
    case Collection;
}
