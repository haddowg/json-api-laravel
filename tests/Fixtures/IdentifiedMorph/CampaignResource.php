<?php

declare(strict_types=1);

namespace haddowg\JsonApiLaravel\Tests\Fixtures\IdentifiedMorph;

use haddowg\JsonApi\Resource\AbstractResource;
use haddowg\JsonApi\Resource\Field\Id;
use haddowg\JsonApi\Resource\Field\MorphTo;
use haddowg\JsonApi\Resource\Field\Str;

/**
 * A campaign targets an advertiser or an agency, stored as two plain columns: a short type
 * name and the target's key.
 */
final class CampaignResource extends AbstractResource
{
    public static string $type = 'campaigns';

    private const array TYPES = ['advertiser' => 'advertisers', 'agency' => 'agencies'];

    public function fields(): array
    {
        return [
            Id::make(),
            Str::make('name'),
            MorphTo::make('target', ['advertisers', 'agencies'])
                ->identifiedBy(static function (mixed $campaign): ?array {
                    if (!$campaign instanceof Campaign && !$campaign instanceof CampaignRecord) {
                        return null;
                    }

                    $type = $campaign->target_type;
                    $id = $campaign->target_id;

                    return $type === null || $id === null ? null : [self::TYPES[$type] ?? $type, $id];
                }),
        ];
    }
}
