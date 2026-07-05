<?php

declare(strict_types=1);

namespace haddowg\JsonApiLaravel\Tests\Fixtures\StandaloneHydrator;

/**
 * The tiny domain object behind the standalone `beacons` type: a plain POPO with no
 * resource class, no Eloquent model — the write-capable standalone-pair witness
 * (serializer + hydrator + in-memory provider/persister, zero `AbstractResource`).
 *
 * @internal
 */
final class Beacon
{
    public function __construct(
        public ?string $id = null,
        public string $label = '',
    ) {}
}
