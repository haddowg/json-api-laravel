<?php

declare(strict_types=1);

namespace haddowg\JsonApiLaravel\Tests\Fixtures\IdentifiedMorph;

/**
 * A view policy for agencies that refuses agency 2, Umbrella, to everyone, guests included.
 */
final class HidesUmbrellaPolicy
{
    public function view(?object $user, object $agency): bool
    {
        return !($agency instanceof Agency || $agency instanceof AgencyRecord) || $agency->name !== 'Umbrella';
    }
}
