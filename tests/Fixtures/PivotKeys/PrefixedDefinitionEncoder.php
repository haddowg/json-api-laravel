<?php

declare(strict_types=1);

namespace haddowg\JsonApiLaravel\Tests\Fixtures\PivotKeys;

use haddowg\JsonApi\Resource\Field\IdEncoderInterface;
use haddowg\JsonApiLaravel\Eloquent\BinaryUuidEncoder;

/**
 * A second encoding of the definitions' binary UUID key: the canonical string behind a
 * `def-` prefix, so a wire id of one definition type never decodes under the other's
 * encoder.
 *
 * @internal
 */
final readonly class PrefixedDefinitionEncoder implements IdEncoderInterface
{
    private const string PREFIX = 'def-';

    public function encode(mixed $storageKey): string
    {
        return self::PREFIX . (new BinaryUuidEncoder())->encode($storageKey);
    }

    public function decode(string $wireId): mixed
    {
        return \str_starts_with($wireId, self::PREFIX) ? (new BinaryUuidEncoder())->decode(\substr($wireId, \strlen(self::PREFIX))) : null;
    }
}
