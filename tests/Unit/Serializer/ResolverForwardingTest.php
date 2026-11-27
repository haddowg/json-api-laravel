<?php

declare(strict_types=1);

namespace haddowg\JsonApiLaravel\Tests\Unit\Serializer;

use haddowg\JsonApi\Request\JsonApiRequestInterface;
use haddowg\JsonApi\Resource\Field\BelongsToMany;
use haddowg\JsonApi\Resource\Field\NestedFieldset;
use haddowg\JsonApi\Resource\ProvidesResourcesInterface;
use haddowg\JsonApi\Resource\SerializerResolverAwareInterface;
use haddowg\JsonApi\Resource\SerializerResolverInterface;
use haddowg\JsonApi\Serializer\SerializerInterface;
use haddowg\JsonApi\Serializer\SerializesNestedFieldsetsInterface;
use haddowg\JsonApiLaravel\Serializer\AbstractPivotParentSerializer;
use haddowg\JsonApiLaravel\Serializer\PivotLinkageParentSerializer;
use haddowg\JsonApiLaravel\Serializer\PivotMetaSerializer;
use haddowg\JsonApiLaravel\Serializer\PivotRenderingResolver;
use haddowg\JsonApiLaravel\Serializer\PivotSubstitutingResolver;
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
#[CoversClass(PivotRenderingResolver::class)]
#[CoversClass(PivotSubstitutingResolver::class)]
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

    #[Test]
    public function theDecoratorsForwardTheNestedFieldsetSelection(): void
    {
        $inner = $this->createStubForIntersectionOfInterfaces([SerializerInterface::class, SerializesNestedFieldsetsInterface::class]);
        $inner->method('getNestedFieldsetAttribute')->willReturn(['street' => 'Main']);
        \assert($inner instanceof SerializerInterface);
        $request = $this->createStub(JsonApiRequestInterface::class);
        $fieldset = new NestedFieldset(['street']);

        self::assertSame(['street' => 'Main'], (new PivotMetaSerializer($inner, []))->getNestedFieldsetAttribute(new \stdClass(), $request, 'address', $fieldset));
        self::assertSame(['street' => 'Main'], (new PivotLinkageParentSerializer($inner, $this->rendering()))->getNestedFieldsetAttribute(new \stdClass(), $request, 'address', $fieldset));
    }

    #[Test]
    public function theResolversForwardTheRegisteredResources(): void
    {
        $server = $this->createStubForIntersectionOfInterfaces([SerializerResolverInterface::class, ProvidesResourcesInterface::class]);
        $server->method('hasResourceFor')->willReturnCallback(static fn(string $type): bool => $type === 'tracks');
        \assert($server instanceof SerializerResolverInterface);

        $rendering = $this->rendering($server);
        self::assertTrue($rendering->hasResourceFor('tracks'));
        self::assertFalse($rendering->hasResourceFor('albums'));
        self::assertTrue((new PivotSubstitutingResolver($rendering, 'tracks', new PivotMetaSerializer($this->createStub(SerializerInterface::class), [])))->hasResourceFor('tracks'));
        self::assertFalse($this->rendering()->hasResourceFor('tracks'));
    }

    private function rendering(?SerializerResolverInterface $server = null): PivotRenderingResolver
    {
        return new PivotRenderingResolver(
            $server ?? $this->createStub(SerializerResolverInterface::class),
            static fn(string $type): array => [],
            static fn(object $parent, BelongsToMany $relation, string $type): ?\Closure => null,
        );
    }
}
