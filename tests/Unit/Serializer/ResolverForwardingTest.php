<?php

declare(strict_types=1);

namespace haddowg\JsonApiLaravel\Tests\Unit\Serializer;

use haddowg\JsonApi\Resource\SerializerResolverAwareInterface;
use haddowg\JsonApi\Resource\SerializerResolverInterface;
use haddowg\JsonApi\Serializer\SerializerInterface;
use haddowg\JsonApiLaravel\Serializer\AbstractPivotParentSerializer;
use haddowg\JsonApiLaravel\Serializer\PivotLinkageParentSerializer;
use haddowg\JsonApiLaravel\Serializer\PivotMetaSerializer;
use haddowg\JsonApiLaravel\Serializer\PivotRenderingResolver;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * The pivot serializer decorators pass the inner serializer's injected resolver through, so a
 * resource they render keeps the links a resource link contributor adds, and report none when
 * the inner serializer has none.
 *
 * @internal
 */
#[CoversClass(PivotMetaSerializer::class)]
#[CoversClass(PivotLinkageParentSerializer::class)]
#[CoversClass(AbstractPivotParentSerializer::class)]
final class ResolverForwardingTest extends TestCase
{
    #[Test]
    public function theDecoratorsForwardTheInjectedResolver(): void
    {
        $resolver = $this->createStub(SerializerResolverInterface::class);
        $inner = $this->createStubForIntersectionOfInterfaces([SerializerInterface::class, SerializerResolverAwareInterface::class]);
        $inner->method('serializerResolver')->willReturn($resolver);
        \assert($inner instanceof SerializerInterface);

        self::assertSame($resolver, (new PivotMetaSerializer($inner, []))->serializerResolver());
        self::assertSame($resolver, (new PivotLinkageParentSerializer($inner, $this->rendering()))->serializerResolver());
    }

    #[Test]
    public function aDecoratorOverASerializerWithNoResolverReportsNone(): void
    {
        $inner = $this->createStub(SerializerInterface::class);

        self::assertNull((new PivotMetaSerializer($inner, []))->serializerResolver());
        self::assertNull((new PivotLinkageParentSerializer($inner, $this->rendering()))->serializerResolver());
    }

    private function rendering(): PivotRenderingResolver
    {
        return new PivotRenderingResolver(
            $this->createStub(SerializerResolverInterface::class),
            static fn(string $type): array => [],
            static fn(object $parent): ?\Closure => null,
        );
    }
}
