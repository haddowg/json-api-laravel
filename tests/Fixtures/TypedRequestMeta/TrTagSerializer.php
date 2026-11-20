<?php

declare(strict_types=1);

namespace haddowg\JsonApiLaravel\Tests\Fixtures\TypedRequestMeta;

use haddowg\JsonApi\OpenApi\Metadata\OperationType;
use haddowg\JsonApi\Request\JsonApiRequestInterface;
use haddowg\JsonApi\Resource\Field\AdditionalProperties;
use haddowg\JsonApi\Resource\Field\Integer;
use haddowg\JsonApi\Resource\MetaContext;
use haddowg\JsonApi\Schema\Link\ResourceLinks;
use haddowg\JsonApi\Serializer\AbstractSerializer;
use haddowg\JsonApi\Serializer\DeclaresDocumentMetaInterface;
use haddowg\JsonApi\Serializer\DeclaresResourceMetaInterface;
use haddowg\JsonApiLaravel\Attribute\AsJsonApiSerializer;
use haddowg\JsonApiLaravel\Operation\Operation;

/**
 * A type whose resource meta and FetchOne document meta each declare a member and open the
 * object with an AdditionalProperties entry. Asked by `X-Clash-Meta`, each entry also
 * returns the declared member's name.
 */
#[AsJsonApiSerializer(type: 'tr-tags', operations: [Operation::FetchOne])]
final class TrTagSerializer extends AbstractSerializer implements DeclaresResourceMetaInterface, DeclaresDocumentMetaInterface
{
    public function resourceMeta(MetaContext $context): iterable
    {
        return [
            Integer::make('uses')->readOnly()->extractUsing(static fn(): int => 2),
            AdditionalProperties::make()->extractUsing(
                static fn(mixed $tag, JsonApiRequestInterface $request): array => $request->hasHeader('X-Clash-Meta') ? ['uses' => 9, 'color' => 'red'] : ['color' => 'red'],
            ),
        ];
    }

    public function documentMeta(OperationType $operation): iterable
    {
        return match ($operation) {
            OperationType::FetchOne => [
                Integer::make('total')->extractUsing(static fn(): int => 1),
                AdditionalProperties::make()->extractUsing(
                    static fn(mixed $tag, JsonApiRequestInterface $request): array => $request->hasHeader('X-Clash-Document-Meta') ? ['total' => 5] : [],
                ),
            ],
            default => [],
        };
    }

    public function getType(mixed $object): string
    {
        return 'tr-tags';
    }

    public function getId(mixed $object): string
    {
        return $object instanceof TrLabel ? $object->id : '';
    }

    public function getMeta(mixed $object, JsonApiRequestInterface $request): array
    {
        return [];
    }

    public function getLinks(mixed $object, JsonApiRequestInterface $request): ?ResourceLinks
    {
        return null;
    }

    public function getAttributes(mixed $object, JsonApiRequestInterface $request): array
    {
        return ['name' => static fn(mixed $tag): string => $tag instanceof TrLabel ? $tag->name : ''];
    }

    public function getDefaultIncludedRelationships(mixed $object): array
    {
        return [];
    }

    public function getRelationships(mixed $object, JsonApiRequestInterface $request): array
    {
        return [];
    }
}
