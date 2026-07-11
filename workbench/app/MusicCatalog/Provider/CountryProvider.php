<?php

declare(strict_types=1);

namespace Workbench\App\MusicCatalog\Provider;

use haddowg\JsonApi\Collection\CollectionResult;
use haddowg\JsonApi\Operation\QueryParameters;
use haddowg\JsonApi\Pagination\OffsetWindow;
use haddowg\JsonApi\Resource\Filter\InMemory\ArrayFilterHandler;
use haddowg\JsonApi\Resource\Filter\Where;
use haddowg\JsonApi\Resource\Sort\InMemory\ArraySortHandler;
use haddowg\JsonApi\Resource\Sort\SortByField;
use haddowg\JsonApiLaravel\DataProvider\AbstractDataProvider;
use haddowg\JsonApiLaravel\DataProvider\CollectionCriteria;
use haddowg\JsonApiLaravel\DataProvider\CriteriaApplier;
use Symfony\Component\Intl\Countries;
use Workbench\App\MusicCatalog\Domain\Country;

/**
 * The reference-data provider: a read-only `countries` source backed by NO database. It
 * sources its rows from `symfony/intl`'s {@see Countries} (id = ISO 3166-1 alpha-2 code,
 * attribute = the localized name) and still serves **filter / sort / pagination over the
 * in-memory list** by reusing the shared {@see CriteriaApplier} and core's reference
 * in-memory filter/sort handlers — so an external/static source is a first-class JSON:API
 * collection, not a special case (PLAN decision 3, bundle ADR 0024).
 *
 * Because a resource-less type declares no field inventory, the
 * {@see \haddowg\JsonApiLaravel\Operation\CrudOperationHandler} cannot hand this provider
 * a filter/sort vocabulary (that lives on a Resource) — so the provider declares its own
 * (`filter[name]` substring + `sort=name`) and rebuilds the criteria around it, then
 * applies {@see CriteriaApplier} exactly as the Eloquent and in-memory providers do.
 * Pagination is likewise driven from the request's `page[number]`/`page[size]` and
 * executed as an {@see OffsetWindow} array slice, since a resource-less type has no
 * server-default paginator.
 *
 * Countries are reference data — never the target of a relationship — so the relationship
 * / batch / pivot seams of the SPI all use the neutral defaults inherited from
 * {@see AbstractDataProvider}; only the three read abstracts are implemented.
 *
 * @extends AbstractDataProvider<object>
 */
final class CountryProvider extends AbstractDataProvider
{
    private const string LOCALE = 'en';

    private const int DEFAULT_PER_PAGE = 25;

    private readonly CriteriaApplier $applier;

    private readonly ArrayFilterHandler $filterHandler;

    private readonly ArraySortHandler $sortHandler;

    public function __construct()
    {
        $this->applier = new CriteriaApplier();
        $this->filterHandler = new ArrayFilterHandler();
        $this->sortHandler = new ArraySortHandler();
    }

    public function supports(string $type): bool
    {
        return $type === 'countries';
    }

    public function fetchOne(string $type, string $id): ?object
    {
        $code = \strtoupper($id);
        if (!Countries::exists($code)) {
            return null;
        }

        return new Country($code, Countries::getName($code, self::LOCALE));
    }

    public function fetchCollection(string $type, CollectionCriteria $criteria): CollectionResult
    {
        // Rebuild the criteria around THIS provider's own vocabulary (the handler supplies
        // none for a resource-less type), then run the shared applier.
        $vocabularyCriteria = new CollectionCriteria(
            $criteria->queryParameters,
            [Where::make('name', 'name', 'like')->build()],
            [SortByField::make('name', 'name')],
            $criteria->window,
        );

        /** @var list<object> $items */
        $items = $this->applier->apply(
            $vocabularyCriteria,
            $this->all(),
            $this->filterHandler,
            $this->sortHandler,
        );

        return $this->window($items, $criteria->queryParameters);
    }

    /**
     * Every country as a {@see Country}. The base order is `symfony/intl`'s own —
     * {@see Countries::getNames()} returns codes keyed in localized-name order for the
     * pinned `self::LOCALE` ('en'), so the first rows are AF, AX, AL, DZ, AS… (Afghanistan,
     * Åland Islands, Albania, Algeria, American Samoa), NOT alpha-2 code order. It is
     * deterministic for a pinned locale + package version (a symfony/intl data update can
     * reorder a row whose display name changes); any requested `sort=name` reorders it.
     *
     * @return list<Country>
     */
    private function all(): array
    {
        $countries = [];
        foreach (Countries::getNames(self::LOCALE) as $code => $name) {
            $countries[] = new Country($code, $name);
        }

        return $countries;
    }

    /**
     * Windows the already filtered/sorted list from the request's
     * `page[number]`/`page[size]` as an {@see OffsetWindow} slice; an absent `page`
     * returns the whole (filtered) collection unwindowed.
     *
     * @param list<object> $items
     *
     * @return CollectionResult<object>
     */
    private function window(array $items, QueryParameters $queryParameters): CollectionResult
    {
        $pagination = $queryParameters->pagination;
        if ($pagination === []) {
            return new CollectionResult($items);
        }

        $number = \max(1, $this->intParam($pagination, 'number', 1));
        $size = \max(1, $this->intParam($pagination, 'size', self::DEFAULT_PER_PAGE));
        $window = new OffsetWindow(($number - 1) * $size, $size);

        return new CollectionResult(
            \array_slice($items, $window->offset, $window->limit),
            \count($items),
        );
    }

    /**
     * Reads an integer page parameter, falling back to `$default` for an absent or
     * non-numeric value.
     *
     * @param array<string, mixed> $pagination
     */
    private function intParam(array $pagination, string $key, int $default): int
    {
        $value = $pagination[$key] ?? null;

        return \is_numeric($value) ? (int) $value : $default;
    }
}
