<?php

declare(strict_types=1);

namespace haddowg\JsonApiLaravel\Tests\Fixtures\IdentifiedMorph;

/**
 * The advertisers' API policy. It refuses a row named Globex, advertiser 2, and one named
 * Initech, which is agency 1, so an agency checked against it would be hidden.
 */
final class AdvertiserNamesPolicy
{
    public function viewAny(?object $user): bool
    {
        return true;
    }

    public function view(?object $user, object $row): bool
    {
        $name = $row instanceof Advertiser || $row instanceof AdvertiserRecord || $row instanceof Agency || $row instanceof AgencyRecord ? $row->name : null;

        return !\in_array($name, ['Globex', 'Initech'], true);
    }
}
