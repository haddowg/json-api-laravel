<?php

declare(strict_types=1);

namespace haddowg\JsonApiLaravel\Tests\Fixtures\IdentifiedMorphMany;

final class AgencyResource extends CompanyResource
{
    public static string $type = 'agencies';
}
