<?php

declare(strict_types=1);

namespace haddowg\JsonApiLaravel\Tests\Fixtures\ModelMap\Unservable;

use haddowg\JsonApi\Resource\AbstractResource;
use haddowg\JsonApi\Resource\Field\Id;
use haddowg\JsonApi\Resource\Field\Str;

/**
 * The unresolvable-type witness (ADR 0019): no `model:` declaration and no `Ghost`
 * model under the configured namespace, so no tier claims `ghosts` — the auto pair must
 * NOT claim it, and a request keeps failing with the pre-existing no-provider wiring
 * error, exactly as before the tiers existed. It lives outside the fixture `JsonApi`
 * dir so the optimize test (whose servability validation would rightly flag an
 * unservable type) can scan a fully-servable app; the tiers feature test adds this dir
 * as a second discovery path.
 *
 * @internal
 */
final class GhostResource extends AbstractResource
{
    public static string $type = 'ghosts';

    public function fields(): array
    {
        return [
            Id::make(),
            Str::make('title'),
        ];
    }
}
