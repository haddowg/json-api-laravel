<?php

declare(strict_types=1);

namespace Workbench\App\Sparse;

/**
 * A plain model for the sparse-by-default witness: a cheap `name` plus an
 * `expensive_score` the {@see SparseWidgetResource} marks
 * {@see \haddowg\JsonApi\Resource\Field\AbstractField::sparseByDefault()}, so the
 * `expensiveScore` attribute renders only when the client names it in
 * `fields[sparseWidgets]`. The property is snake_case so the resource's
 * `storedAs('expensive_score')` reads the same member off this POPO and off the
 * {@see \Workbench\App\Models\SparseWidget} Eloquent column.
 */
final class SparseWidget
{
    public function __construct(
        public string $id = '',
        public string $name = '',
        public int $expensive_score = 0,
    ) {}
}
