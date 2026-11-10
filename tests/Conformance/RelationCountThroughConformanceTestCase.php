<?php

declare(strict_types=1);

namespace haddowg\JsonApiLaravel\Tests\Conformance;

use haddowg\JsonApiLaravel\JsonApiServiceProvider;
use haddowg\JsonApiLaravel\Tests\Fixtures\CountThrough\CountThroughServiceProvider;
use Orchestra\Testbench\TestCase as Orchestra;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\Test;

/**
 * Relationship filters on a counted relation, asserted identically on the in-memory witness
 * ({@see InMemoryRelationCountThroughConformanceTest}) and the reference Eloquent provider
 * ({@see EloquentRelationCountThroughConformanceTest}) over the same
 * {@see CountThroughServiceProvider} seed. A `WhereThrough`, `WhereHas` or
 * `WhereDoesntHave` on a campaign's leads or shortlist tests each lead, so a `withCount`
 * total and a Relationship Queries window total count the leads it keeps. Campaigns and
 * leads both have a `brand`, so a filter tested on the campaign instead counts the wrong
 * leads.
 */
#[Group('spec:fetching-relationships')]
#[Group('spec:fetching-filtering')]
abstract class RelationCountThroughConformanceTestCase extends Orchestra
{
    private const string ACCEPT = 'application/vnd.api+json;profile="https://haddowg.github.io/json-api/profiles/countable/ https://haddowg.github.io/json-api/profiles/relationship-queries/"';

    /**
     * `eloquent` or `memory`.
     */
    abstract protected function store(): string;

    /**
     * @param \Illuminate\Foundation\Application $app
     *
     * @return array<int, class-string>
     */
    protected function getPackageProviders($app): array
    {
        /** @var \Illuminate\Contracts\Config\Repository $config */
        $config = $app['config'];
        $config->set('count_through.store', $this->store());

        return [JsonApiServiceProvider::class, CountThroughServiceProvider::class];
    }

    #[Test]
    public function aWithCountCountsTheMembersTheFilterKeeps(): void
    {
        foreach (self::filteredCounts() as $case => [$relation, $filter, $expected]) {
            $document = $this->fetch(\sprintf('/api/ctCampaigns?withCount=%1$s&relatedQuery[%1$s][filter][%2$s', $relation, $filter), $case);

            self::assertSame($expected, $this->totals($document, $relation), $case);
        }
    }

    #[Test]
    public function aWindowedIncludeTotalsTheMembersTheFilterKeeps(): void
    {
        foreach (self::filteredCounts() as $case => [$relation, $filter, $expected]) {
            $document = $this->fetch(\sprintf('/api/ctCampaigns?include=%1$s&withCount=%1$s&relatedQuery[%1$s][filter][%2$s', $relation, $filter), $case);

            self::assertSame($expected, $this->totals($document, $relation), $case);
            self::assertSame($expected, \array_map(\count(...), $this->linkage($document, $relation)), $case);
        }
    }

    /**
     * Each case's relation, `relatedQuery` filter and expected total per campaign.
     *
     * @return iterable<string, array{string, string, array<int, int>}>
     */
    private static function filteredCounts(): iterable
    {
        yield 'a relation-scoped WhereThrough on a foreign key' => ['leads', 'leadBrand]=Acme', [1 => 1, 2 => 0, 3 => 1]];
        yield 'a requested WhereThrough on a foreign key' => ['leads', 'brandName]=Globex', [1 => 1, 2 => 1, 3 => 0]];
        yield 'a WhereThrough whose first hop only the lead has' => ['leads', 'templateName]=Welcome', [1 => 2, 2 => 0, 3 => 1]];
        yield 'a WhereHas on a foreign key' => ['leads', 'branded]=1', [1 => 2, 2 => 1, 3 => 1]];
        yield 'a WhereDoesntHave on a foreign key' => ['leads', 'unbranded]=1', [1 => 1, 2 => 0, 3 => 1]];
        yield 'a relation-scoped WhereThrough on a pivot table' => ['shortlist', 'leadBrand]=Acme', [1 => 2, 2 => 0, 3 => 0]];
        yield 'a requested WhereThrough on a pivot table' => ['shortlist', 'templateName]=Reminder', [1 => 2, 2 => 0, 3 => 0]];
        yield 'a WhereHas on a pivot table' => ['shortlist', 'branded]=1', [1 => 3, 2 => 1, 3 => 0]];
    }

    /**
     * @return array<string, mixed>
     */
    private function fetch(string $path, string $case): array
    {
        $response = $this->get($path, ['Accept' => self::ACCEPT]);
        self::assertSame(200, $response->getStatusCode(), $case . ' ' . $response->getContent());

        $document = $response->json();
        self::assertIsArray($document);

        /** @var array<string, mixed> $document */
        return $document;
    }

    /**
     * Each campaign's `meta.total` for `$relation`, keyed by campaign id.
     *
     * @param array<string, mixed> $document
     *
     * @return array<int, int>
     */
    private function totals(array $document, string $relation): array
    {
        $totals = [];
        foreach ($this->relationshipsOf($document, $relation) as $id => $relationship) {
            $total = \data_get($relationship, 'meta.total');
            self::assertIsInt($total, \sprintf('campaign %d %s carries a total', $id, $relation));
            $totals[$id] = $total;
        }

        return $totals;
    }

    /**
     * Each campaign's linkage ids for `$relation`, keyed by campaign id.
     *
     * @param array<string, mixed> $document
     *
     * @return array<int, list<string>>
     */
    private function linkage(array $document, string $relation): array
    {
        $linkage = [];
        foreach ($this->relationshipsOf($document, $relation) as $id => $relationship) {
            $data = $relationship['data'] ?? null;
            self::assertIsArray($data);
            $linkage[$id] = [];
            foreach ($data as $identifier) {
                $member = \data_get($identifier, 'id');
                self::assertIsString($member);
                $linkage[$id][] = $member;
            }
        }

        return $linkage;
    }

    /**
     * @param array<string, mixed> $document
     *
     * @return array<int, array<string, mixed>>
     */
    private function relationshipsOf(array $document, string $relation): array
    {
        $data = $document['data'] ?? null;
        self::assertIsArray($data);

        $byCampaign = [];
        foreach ($data as $resource) {
            $id = \data_get($resource, 'id');
            self::assertIsString($id);
            $relationship = \data_get($resource, 'relationships.' . $relation);
            self::assertIsArray($relationship);
            /** @var array<string, mixed> $relationship */
            $byCampaign[(int) $id] = $relationship;
        }
        \ksort($byCampaign);

        return $byCampaign;
    }
}
