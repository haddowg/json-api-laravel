<?php

declare(strict_types=1);

namespace haddowg\JsonApiLaravel\Tests\Fixtures\StandaloneHydrator;

use haddowg\JsonApi\Hydrator\HydratorInterface;
use haddowg\JsonApi\Request\JsonApiRequestInterface;
use haddowg\JsonApiLaravel\Attribute\AsJsonApiHydrator;

/**
 * A **hydrator-only** standalone type: `ingest-commands` registers a write shape with
 * core (resolvable through `Server::hydratorFor()`) but has no serializer, no resource
 * and no operation allow-list — so it exposes **no endpoints** of its own, the
 * operation-gating default the tests pin (the write-shape half a custom action's
 * decoupled `inputType` document would consume).
 *
 * @internal
 */
#[AsJsonApiHydrator(type: 'ingest-commands')]
final class IngestCommandHydrator implements HydratorInterface
{
    public function hydrate(JsonApiRequestInterface $request, mixed $domainObject): mixed
    {
        return $domainObject;
    }
}
