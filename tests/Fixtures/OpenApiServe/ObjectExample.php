<?php

declare(strict_types=1);

namespace haddowg\JsonApiLaravel\Tests\Fixtures\OpenApiServe;

/**
 * An application object used as a schema example, which a serve artifact only carries when
 * its class is listed in `jsonapi.openapi.serve_allowed_classes`.
 */
final readonly class ObjectExample implements \JsonSerializable
{
    public function __construct(public string $value = 'kept') {}

    public function jsonSerialize(): string
    {
        return $this->value;
    }
}
