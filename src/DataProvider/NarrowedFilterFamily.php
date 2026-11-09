<?php

declare(strict_types=1);

namespace haddowg\JsonApiLaravel\DataProvider;

use haddowg\JsonApi\Request\JsonApiRequestInterface;
use haddowg\JsonApi\Resource\Filter\AcceptsList;
use haddowg\JsonApi\Resource\Filter\FilterFamilyInterface;
use haddowg\JsonApi\Resource\Filter\FilterInterface;
use haddowg\JsonApi\Resource\Filter\ListValue;

/**
 * A filter family that answers only the keys whose resolved filter `$admits` accepts, so a
 * path that drops some exact filters drops the same filters when a family resolves them.
 * {@see RelationCriteriaFactory} wraps a relation's families in one to leave out the
 * `pivot.`-columned filters a provider cannot reach.
 *
 * @internal
 */
final readonly class NarrowedFilterFamily implements FilterFamilyInterface, AcceptsList
{
    /**
     * @param \Closure(FilterInterface): bool $admits
     */
    public function __construct(
        private FilterFamilyInterface $family,
        private \Closure $admits,
    ) {}

    public function key(): string
    {
        return $this->family->key();
    }

    public function constraints(): array
    {
        return $this->family->constraints();
    }

    public function suffixPattern(): string
    {
        return $this->family->suffixPattern();
    }

    public function listValue(): ?ListValue
    {
        return $this->family instanceof AcceptsList ? $this->family->listValue() : null;
    }

    public function resolve(string $suffix, JsonApiRequestInterface $request): ?FilterInterface
    {
        $filter = $this->family->resolve($suffix, $request);

        return $filter !== null && ($this->admits)($filter) ? $filter : null;
    }
}
