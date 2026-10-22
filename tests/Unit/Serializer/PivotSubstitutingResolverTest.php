<?php

declare(strict_types=1);

namespace haddowg\JsonApiLaravel\Tests\Unit\Serializer;

use haddowg\JsonApi\Resource\SerializerResolverInterface;
use haddowg\JsonApi\Serializer\SerializerInterface;
use haddowg\JsonApi\Server\Server;
use haddowg\JsonApiLaravel\Serializer\PivotMetaSerializer;
use haddowg\JsonApiLaravel\Serializer\PivotSubstitutingResolver;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * @internal
 */
#[CoversClass(PivotSubstitutingResolver::class)]
final class PivotSubstitutingResolverTest extends TestCase
{
    #[Test]
    public function itPassesTheInnerResolversOutputTimezoneThrough(): void
    {
        self::assertSame('UTC', $this->decorating(Server::make()->withOutputTimezone('UTC'))->outputTimezone());
        self::assertNull($this->decorating(Server::make())->outputTimezone());
    }

    #[Test]
    public function anInnerResolverWithoutATimezoneMeansNone(): void
    {
        self::assertNull($this->decorating($this->createStub(SerializerResolverInterface::class))->outputTimezone());
    }

    private function decorating(SerializerResolverInterface $inner): PivotSubstitutingResolver
    {
        return new PivotSubstitutingResolver(
            $inner,
            'tracks',
            new PivotMetaSerializer($this->createStub(SerializerInterface::class), []),
        );
    }
}
