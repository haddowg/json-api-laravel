<?php

declare(strict_types=1);

namespace haddowg\JsonApiLaravel\Tests\Fixtures\OpenApiServe;

use haddowg\JsonApi\OpenApi\OpenApi;
use haddowg\JsonApi\OpenApi\Paths;
use haddowg\JsonApiLaravel\OpenApi\OpenApiFactoryInterface;

/**
 * Build-time half of the feature-flag example: marks every `/genres` operation with an
 * `x-feature: genres` extension and the album create with `x-feature: catalog-admin`, which
 * {@see FeatureGateDecorator} reads per request.
 */
final class FeatureMarkingDecorator implements OpenApiFactoryInterface
{
    public function decorate(OpenApi $document, string $server): OpenApi
    {
        $items = [];
        foreach ($document->paths->items ?? [] as $path => $item) {
            $operations = [];
            foreach ($item->operations as $method => $operation) {
                if (\str_starts_with($path, '/genres')) {
                    $operation = $operation->withExtension('x-feature', 'genres');
                } elseif ($path === '/albums' && $method === 'post') {
                    $operation = $operation->withExtension('x-feature', 'catalog-admin');
                }
                $operations[$method] = $operation;
            }
            $items[$path] = $item->withOperations($operations);
        }

        return $document->withPaths(new Paths($items));
    }
}
