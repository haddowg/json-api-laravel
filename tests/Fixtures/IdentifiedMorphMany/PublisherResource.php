<?php

declare(strict_types=1);

namespace haddowg\JsonApiLaravel\Tests\Fixtures\IdentifiedMorphMany;

final class PublisherResource extends CompanyResource
{
    public static string $type = 'publishers';
}
