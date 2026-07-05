<?php

declare(strict_types=1);

namespace haddowg\JsonApiLaravel\Tests\Fixtures\StandaloneHydrator;

use haddowg\JsonApi\Hydrator\HydratorInterface;
use haddowg\JsonApi\Request\JsonApiRequestInterface;
use haddowg\JsonApiLaravel\Attribute\AsJsonApiHydrator;

/**
 * The write half of the write-capable standalone `beacons` pair: a hand-written
 * {@see HydratorInterface} registered with {@see AsJsonApiHydrator} and **no**
 * `AbstractResource` — its presence is what makes the {@see BeaconSerializer}
 * allow-list's `Create`/`Update` routable. It reads the `label` attribute off the
 * request document and writes it onto the {@see Beacon} the persister instantiated
 * (create) or the provider loaded (update); an absent member leaves the stored value
 * untouched, so a partial `PATCH` is correct.
 *
 * @internal
 */
#[AsJsonApiHydrator(type: 'beacons')]
final class BeaconHydrator implements HydratorInterface
{
    public function hydrate(JsonApiRequestInterface $request, mixed $domainObject): mixed
    {
        \assert($domainObject instanceof Beacon);

        if (\array_key_exists('label', $request->getResourceAttributes())) {
            $label = $request->getResourceAttribute('label');
            $domainObject->label = \is_string($label) ? $label : '';
        }

        return $domainObject;
    }
}
