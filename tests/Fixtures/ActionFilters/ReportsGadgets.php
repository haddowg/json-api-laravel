<?php

declare(strict_types=1);

namespace haddowg\JsonApiLaravel\Tests\Fixtures\ActionFilters;

use haddowg\JsonApi\Response\MetaResponse;
use haddowg\JsonApiLaravel\Action\ActionContext;
use Illuminate\Database\Eloquent\Builder;

/**
 * Answers with the ids of the filtered gadgets, read through each(), and the ids the
 * filtered query selects when the provider has one.
 */
trait ReportsGadgets
{
    public function handle(ActionContext $context): MetaResponse
    {
        $filtered = $context->filtered();
        $serializer = $context->serializer();

        $ids = [];
        foreach ($filtered->each(2) as $gadget) {
            $ids[] = $serializer->getId($gadget);
        }

        $query = $filtered->query();
        $queryIds = $query instanceof Builder
            ? \array_map(static fn(mixed $id): string => \is_scalar($id) ? (string) $id : '', $query->pluck('id')->all())
            : null;

        return $context->meta(['ids' => $ids, 'builder' => $query instanceof Builder, 'queryIds' => $queryIds]);
    }
}
