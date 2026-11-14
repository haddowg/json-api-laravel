<?php

declare(strict_types=1);

namespace haddowg\JsonApiLaravel\DataProvider;

/**
 * A filter handler that needs the JSON:API type its filters run against, such as to
 * decode a wire id through that type's own id encoder when several types share one
 * model. The {@see CriteriaApplier} hands it the type its criteria's paths name.
 *
 * @internal
 */
interface TargetsTypeInterface
{
    /**
     * A copy of this handler that applies filters running against `$type`, whose
     * declaration names the types `$named`.
     *
     * @param list<string> $named
     */
    public function forType(string $type, array $named = []): static;
}
