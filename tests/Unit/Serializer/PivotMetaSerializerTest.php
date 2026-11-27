<?php

declare(strict_types=1);

namespace haddowg\JsonApiLaravel\Tests\Unit\Serializer;

use haddowg\JsonApi\Request\JsonApiRequestInterface;
use haddowg\JsonApi\Resource\Field\AdditionalProperties;
use haddowg\JsonApi\Resource\Field\BelongsToMany;
use haddowg\JsonApi\Resource\Field\Integer;
use haddowg\JsonApi\Resource\Field\PivotEdge;
use haddowg\JsonApi\Resource\Field\Str;
use haddowg\JsonApi\Serializer\SerializerInterface;
use haddowg\JsonApiLaravel\Serializer\PivotMetaSerializer;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * Every member of a relation with pivot fields carries a `meta.pivot` object: empty when every
 * field is hidden, and with `null` stored fields when the member has no pivot row.
 *
 * @internal
 */
#[CoversClass(PivotMetaSerializer::class)]
final class PivotMetaSerializerTest extends TestCase
{
    #[Test]
    public function aMemberWithNoPivotRowRendersItsStoredFieldsAsNullAndItsComputedFields(): void
    {
        $relation = BelongsToMany::make('records', 'records')->fields(
            Integer::make('position'),
            Str::make('label')->computedUsing(static fn(mixed $edge): string => $edge instanceof PivotEdge ? 'computed' : ''),
        )->build();
        $serializer = new PivotMetaSerializer($this->inner(), ['t9' => ['position' => 1]], $relation, new \stdClass(), null, static fn(object $member): ?array => null);

        self::assertSame('{"pivot":{"position":null,"label":"computed"}}', \json_encode($serializer->getMeta((object) ['id' => 't1'], $this->request())));
    }

    #[Test]
    public function aMemberWhoseEveryPivotFieldIsHiddenRendersAnEmptyObject(): void
    {
        $relation = BelongsToMany::make('records', 'records')->fields(
            Integer::make('position')->hidden(static fn(): bool => true),
        )->build();
        $serializer = new PivotMetaSerializer($this->inner(), ['t1' => ['position' => 1]], $relation, new \stdClass());

        self::assertSame('{"pivot":{}}', \json_encode($serializer->getMeta((object) ['id' => 't1'], $this->request())));
    }

    #[Test]
    public function aRelationDeclaringOnlyAdditionalPropertiesRendersNoPivot(): void
    {
        $relation = BelongsToMany::make('records', 'records')->fields(AdditionalProperties::make())->build();
        $serializer = new PivotMetaSerializer($this->inner(), ['t1' => ['extra' => 1]], $relation, new \stdClass());

        self::assertSame('[]', \json_encode($serializer->getMeta((object) ['id' => 't1'], $this->request())));
    }

    private function inner(): SerializerInterface
    {
        $inner = $this->createStub(SerializerInterface::class);
        $inner->method('getId')->willReturnCallback(static fn(mixed $object): string => \is_object($object) && \is_string($object->id ?? null) ? $object->id : '');
        $inner->method('getMeta')->willReturn([]);

        return $inner;
    }

    private function request(): JsonApiRequestInterface
    {
        return $this->createStub(JsonApiRequestInterface::class);
    }
}
