<?php

declare(strict_types=1);

namespace haddowg\JsonApiLaravel\DataProvider;

/**
 * A filter handler that keeps a filter from matching through rows the caller may not read:
 * the {@see CriteriaApplier} hands it the {@see FilterPaths} each filter runs with, which say
 * which relations admit no row and, in memory, which rows each hop's provider admits.
 *
 * @internal
 */
interface ScopesFilterHopsInterface
{
    /**
     * A copy of this handler that applies the next filter with `$paths`.
     */
    public function forPaths(FilterPaths $paths): static;
}
