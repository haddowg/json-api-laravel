<?php

declare(strict_types=1);

namespace haddowg\JsonApiLaravel\Tests\Fixtures\IdentifiedMorphMany;

use Illuminate\Database\Eloquent\Attributes\ScopedBy;

/**
 * The agencies with {@see HidesHiddenAgencies} applied.
 */
#[ScopedBy(HidesHiddenAgencies::class)]
final class MtmHiddenAgency extends MtmAgency {}
