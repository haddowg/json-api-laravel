<?php

declare(strict_types=1);

namespace haddowg\JsonApiLaravel\Action;

use haddowg\JsonApi\Request\JsonApiRequestInterface;

/**
 * An optional hook a {@see ActionHandlerInterface} MAY also implement for a
 * {@see ActionInput::Document} action whose `inputType` is a **bespoke command DTO**
 * carrying only a serializer/hydrator pair (no persister) — there is no persister to
 * {@see \haddowg\JsonApiLaravel\DataPersister\DataPersisterInterface::instantiate()} the
 * blank instance, so the handler supplies it itself (the Laravel twin of the bundle's
 * `ActionInputFactoryInterface`).
 *
 * When a handler implements this, the {@see ActionInvoker} calls {@see newInput()} to
 * obtain the fresh instance, runs the always-on validation bridge against `inputType`,
 * then hydrates the request body onto it. A handler that does **not** implement this
 * falls back to the `inputType` persister's `instantiate()` (the common case where
 * `inputType` defaults to the mount `type`).
 */
interface ActionInputFactoryInterface
{
    /**
     * A fresh, blank instance of the action's `inputType` for the validation bridge to
     * validate and the type's hydrator to hydrate the request `$body` onto.
     */
    public function newInput(JsonApiRequestInterface $body): object;
}
