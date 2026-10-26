<?php

declare(strict_types=1);

namespace haddowg\JsonApiLaravel\OpenApi\Metadata;

use haddowg\JsonApi\OpenApi\Metadata\DeclaresAlwaysCounted;
use haddowg\JsonApi\OpenApi\Metadata\DeclaresRelationExampleId;
use haddowg\JsonApi\OpenApi\Metadata\DeclaresRelationUriFieldName;
use haddowg\JsonApi\OpenApi\Metadata\DeclaresWriteOnlyRelation;
use haddowg\JsonApi\OpenApi\Schema;
use haddowg\JsonApi\Resource\Field\AbstractRelationValue;
use haddowg\JsonApi\Resource\Field\ExemplifiedRelationInterface;
use haddowg\JsonApi\Resource\Field\RelationInterface;
use haddowg\JsonApiLaravel\OpenApi\PivotFields;

/**
 * Adapts a core {@see RelationInterface} (a resource's relation field) to the OpenAPI
 * {@see \haddowg\JsonApi\OpenApi\Metadata\RelationMetadataInterface} the projector consumes.
 *
 * Most accessors delegate straight to the relation's own already-resolved facts
 * (endpoint exposure, mutation flags, includability, filters/sorts, the linkage example
 * id). The derived facts — the related-collection page {@see Schema}, whether that
 * paginator always counts, and the related-endpoint includable paths — are resolved by the {@see MetadataSource} (which has the
 * {@see \haddowg\JsonApi\Server\Server} fallback chain + the relation graph) and
 * passed in.
 */
final readonly class RelationMetadata implements DeclaresWriteOnlyRelation, DeclaresRelationUriFieldName, DeclaresRelationExampleId, DeclaresAlwaysCounted
{
    /**
     * @param list<string> $relatedIncludablePaths the related type's includable paths (resolved by the source)
     */
    public function __construct(
        private RelationInterface $relation,
        private ?Schema $pageSchema,
        private array $relatedIncludablePaths,
        private bool $alwaysCounts = false,
    ) {}

    public function exampleId(): ?string
    {
        return $this->relation instanceof ExemplifiedRelationInterface ? $this->relation->exampleId() : null;
    }

    public function alwaysCounts(): bool
    {
        return $this->alwaysCounts;
    }

    public function name(): string
    {
        return $this->relation->name();
    }

    public function uriFieldName(): string
    {
        return $this->relation instanceof AbstractRelationValue ? $this->relation->uriFieldName() : $this->relation->name();
    }

    public function relatedTypes(): array
    {
        return $this->relation->relatedTypes();
    }

    public function isWriteOnly(): bool
    {
        return $this->relation->isWriteOnly();
    }

    public function isToMany(): bool
    {
        return $this->relation->isToMany();
    }

    public function description(): ?string
    {
        return $this->relation->getDescription();
    }

    public function isIncludable(): bool
    {
        return $this->relation->isIncludable();
    }

    public function exposesRelatedEndpoint(): bool
    {
        return $this->relation->exposesRelatedEndpoint();
    }

    public function exposesRelationshipEndpoint(): bool
    {
        return $this->relation->exposesRelationshipEndpoint();
    }

    public function allowsReplace(): bool
    {
        return $this->relation->allowsReplace();
    }

    public function allowsAdd(): bool
    {
        return $this->relation->allowsAdd();
    }

    public function allowsRemove(): bool
    {
        return $this->relation->allowsRemove();
    }

    public function isCountable(): bool
    {
        return $this->relation->isCountable();
    }

    public function securityRead(): string|bool|null
    {
        return $this->relation->securityRead();
    }

    public function securityMutate(): string|bool|null
    {
        return $this->relation->securityMutate();
    }

    public function pageSchema(): ?Schema
    {
        return $this->pageSchema;
    }

    public function filters(): array
    {
        return $this->relation->allFilters();
    }

    public function sorts(): array
    {
        // A belongsToMany's pivot fields contribute an auto-derived `?sort=<field>`
        // vocabulary on this relation's related/relationship endpoints. Surface them
        // here so the OpenAPI `sort` enum advertises them too — mirroring how the pivot
        // FILTERS, being author-declared in filters(), are already advertised. Empty for
        // a non-pivot relation.
        return [...$this->relation->sorts(), ...PivotFields::sortsFor($this->relation)];
    }

    public function relatedIncludablePaths(): array
    {
        return $this->relatedIncludablePaths;
    }

    public function pivotFields(): array
    {
        // A belongsToMany relation's declared pivot fields (real FieldInterface
        // definitions) — the source PivotFields::sortsFor() derives the pivot sort
        // vocabulary from the same set. Empty for any non-pivot relation, so the
        // projector types meta.pivot only where the runtime actually renders it.
        return PivotFields::declaredFor($this->relation);
    }
}
