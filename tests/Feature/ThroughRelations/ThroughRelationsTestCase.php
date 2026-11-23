<?php

declare(strict_types=1);

namespace haddowg\JsonApiLaravel\Tests\Feature\ThroughRelations;

use haddowg\JsonApiLaravel\JsonApiServiceProvider;
use haddowg\JsonApiLaravel\Tests\Fixtures\ThroughRelations\ThroughRelationsServiceProvider;
use Illuminate\Testing\TestResponse;
use Orchestra\Testbench\TestCase as Orchestra;
use PHPUnit\Framework\Attributes\Test;

/**
 * Filters and sorts declared with `->through()`, with identical assertions on both stores. See
 * {@see ThroughRelationsServiceProvider} for the graph; the advertiser Shadow is hidden by the
 * advertisers' scope, so leads of the autumn campaign reach no advertiser.
 *
 * @internal
 */
abstract class ThroughRelationsTestCase extends Orchestra
{
    private const string MEDIA_TYPE = 'application/vnd.api+json';

    private const string QUERIES = 'application/vnd.api+json; profile="https://haddowg.github.io/json-api/profiles/relationship-queries/"';

    private const string SPRING = ThroughRelationsServiceProvider::SPRING;

    private const string SUMMER = ThroughRelationsServiceProvider::SUMMER;

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
        $config->set('through_relations.store', $this->store());

        return [JsonApiServiceProvider::class, ThroughRelationsServiceProvider::class];
    }

    #[Test]
    public function aToOneHopComparesTheRelatedField(): void
    {
        self::assertSame(['1', '4'], $this->leadIds('filter[campaignName]=spring'));
        self::assertSame(['1', '3', '4'], $this->leadIds('filter[budget][min]=200'));
    }

    #[Test]
    public function aMultiHopPathComparesTheLastRelatedField(): void
    {
        self::assertSame(['1', '4'], $this->leadIds('filter[advertiserName]=Acme'));
        self::assertSame(['2'], $this->leadIds('filter[advertiserName]=Globex'));
    }

    #[Test]
    public function aDateRangeOverARelatedDateComparesCalendarDays(): void
    {
        self::assertSame(['1', '2', '4'], $this->leadIds('filter[campaignDates][min]=2026-03-01&filter[campaignDates][max]=2026-06-15'));
        $this->assertBadValue('filter[campaignDates][min]=2026-03-01T00:00:00Z', 'filter[campaignDates]');
    }

    #[Test]
    public function aRangeThroughAPathRefusesAValueThatIsNotAnObjectOfItsBounds(): void
    {
        $this->assertBadValue('filter[campaignDates]=2026-03-01', 'filter[campaignDates]');
        $this->assertBadValue('filter[campaignDates][after]=2026-03-01', 'filter[campaignDates]');
        $this->assertBadValue('filter[budget]=200', 'filter[budget]');
        $this->assertBadValue('filter[budget][min]=200&filter[budget][over]=100', 'filter[budget]');
        $this->assertBadValue('filter[budget][max][]=200', 'filter[budget]');

        $this->fetch('/api/thrCampaigns/' . self::SPRING . '/leads?filter[budget]=200')->assertStatus(400)->assertJsonPath('errors.0.source.parameter', 'filter[budget]');
        $this->get('/api/thrCampaigns/' . self::SPRING . '?include=leads&relatedQuery[leads][filter][budget]=200', ['Accept' => self::QUERIES])->assertStatus(400)->assertJsonPath('errors.0.source.parameter', 'filter[budget]');
    }

    #[Test]
    public function anIdFilterThroughAPathComparesTheReachedIdWithItsFormatAndEncoder(): void
    {
        self::assertSame(['1', '4'], $this->leadIds('filter[campaign]=' . self::SPRING));
        self::assertSame(['2', '3', '6'], $this->leadIds('filter[notCampaign]=' . self::SPRING));
        self::assertSame(['2'], $this->leadIds('filter[advertiser]=7'));
        self::assertSame(['1', '5'], $this->leadIds('filter[template]=tpl-1'));

        $this->assertBadValue('filter[campaign]=spring', 'filter[campaign]');
        $this->assertBadValue('filter[advertiser]=seven', 'filter[advertiser]');
        $this->assertBadValue('filter[template]=1', 'filter[template]');
    }

    #[Test]
    public function anIdFilterKeyedByARelationComparesItsIdsWithNoPath(): void
    {
        self::assertSame(['1', '5'], $this->leadIds('filter[templates]=tpl-1'));
        self::assertSame(['2', '3', '6'], $this->leadIds('filter[owningCampaign]=' . self::SPRING));
        $this->assertBadValue('filter[templates]=1', 'filter[templates]');
    }

    #[Test]
    public function aToManyHopMatchesWhenAnyMemberMatches(): void
    {
        self::assertSame(['1', '2'], $this->leadIds('filter[templateName]=Reminder'));
        self::assertSame(['1', '4', '5'], $this->leadIds('filter[templateName][]=Welcome&filter[templateName][]=Survey'));
        // Any member outside the list, not "no member in it".
        self::assertSame(['1', '2', '4'], $this->leadIds('filter[notTemplateName]=Welcome'));
    }

    #[Test]
    public function aDeserializedListIsComparedAtTheEndOfThePath(): void
    {
        self::assertSame(['1', '2', '5'], $this->leadIds('filter[templateGroup]=onboarding'));
        self::assertSame(['1', '2', '4', '5'], $this->leadIds('filter[templateGroup][]=feedback&filter[templateGroup][]=onboarding'));
        self::assertSame(['4'], $this->leadIds('filter[notTemplateGroup]=onboarding'));
        // The enum checks the requested group, not the names it stands for.
        $this->assertBadValue('filter[templateGroup]=Welcome', 'filter[templateGroup]');
    }

    #[Test]
    public function anEmptyDeserializedListMatchesNothingAndExcludesNothing(): void
    {
        self::assertSame([], $this->leadIds('filter[templateGroup]=retired'));
        self::assertSame(['1', '2', '4', '5'], $this->leadIds('filter[notTemplateGroup]=retired'));
    }

    #[Test]
    public function aDeserializedDefaultIsInTheRequestedVocabulary(): void
    {
        self::assertSame(['4'], $this->ids('/api/thrAdvertisers'));
        self::assertSame(['7'], $this->ids('/api/thrAdvertisers?filter[season]=mid'));
        self::assertSame(['4', '7'], $this->ids('/api/thrAdvertisers?filter[season][]=early&filter[season][]=mid'));
        // Only the hidden advertiser runs a late campaign, and none runs one in no season.
        self::assertSame([], $this->ids('/api/thrAdvertisers?filter[season]=late'));
        self::assertSame([], $this->ids('/api/thrAdvertisers?filter[season]=none'));
    }

    #[Test]
    public function aMissingRelationNeverMatchesAThroughFilter(): void
    {
        // Katherine has no campaign: no null test through the campaign matches her.
        self::assertSame(['1', '2', '4', '6'], $this->leadIds('filter[unarchived]=1'));
        self::assertSame(['1', '2', '4', '6'], $this->leadIds('filter[archived]=false'));
        self::assertSame(['3'], $this->leadIds('filter[archived]=true'));
        self::assertSame(['5'], $this->leadIds('filter[noCampaign]=1'));
    }

    #[Test]
    public function existenceFiltersThroughAPathFindEmptyRelations(): void
    {
        self::assertSame(['1', '2', '4'], $this->leadIds('filter[advertised]=1'));
        // Winter has no advertiser and autumn's is hidden by its scope.
        self::assertSame(['3', '6'], $this->leadIds('filter[unadvertised]=1'));
    }

    #[Test]
    public function aRowTheRelatedScopeHidesDoesNotExistForMatching(): void
    {
        self::assertSame([], $this->leadIds('filter[advertiserName]=Shadow'));
        self::assertSame([], $this->leadIds('filter[advertiser]=9'));
    }

    #[Test]
    public function groupsAndFamiliesApplyEachPath(): void
    {
        self::assertSame(['4'], $this->leadIds('filter[search]=Survey'));
        self::assertSame(['1', '4'], $this->leadIds('filter[search]=Acme'));
        self::assertSame(['2'], $this->leadIds('filter[via.advertiser]=Globex'));
    }

    #[Test]
    public function theDeprecatedTraversalStillFiltersAsBefore(): void
    {
        self::assertSame(['1', '4'], $this->leadIds('filter[legacyAdvertiserName]=Acme'));
        self::assertSame(['2'], $this->leadIds('filter[legacyAdvertiserName]=Globex'));
    }

    #[Test]
    public function anOffsetPageSortsByTheReachedFieldAndNullWhereThereIsNone(): void
    {
        // Hedy's advertiser is hidden, Katherine has no campaign and Lin's has no advertiser.
        self::assertSame(['3', '5', '6', '1', '4', '2'], $this->ids('/api/thrLeads?sort=advertiserName,name'), 'ascending');
        self::assertSame(['2', '1', '4', '3', '5', '6'], $this->ids('/api/thrLeads?sort=-advertiserName,name'), 'descending');
        self::assertSame(['5', '1', '4', '2', '3', '6'], $this->ids('/api/thrLeads?sort=campaignStart,name'), 'one hop');
        self::assertSame(['1', '4', '3', '2', '6', '5'], $this->ids('/api/thrLeads?sort=-by.budget,name'), 'from a family');
    }

    #[Test]
    public function cursorPagesFollowTheirNextLinksThroughTheReachedField(): void
    {
        // A cursor page orders null as the largest value.
        self::assertSame(['1', '4', '2', '3', '5', '6'], $this->walkCursor('advertiserName,name'), 'ascending');
        self::assertSame(['3', '5', '6', '2', '1', '4'], $this->walkCursor('-advertiserName,name'), 'descending');
        self::assertSame(['1', '4', '2', '3', '6', '5'], $this->walkCursor('campaignStart,name'), 'one hop');
        self::assertSame(['5', '1', '4', '3', '2', '6'], $this->walkCursor('-by.budget,name'), 'from a family');
    }

    #[Test]
    public function aSortThroughASelfReferencingRelationReadsTheOtherRow(): void
    {
        // Hedy was referred by Ada and Joan by Grace; nobody referred the others.
        self::assertSame(['1', '2', '5', '6', '3', '4'], $this->ids('/api/thrLeads?sort=referrerName,name'), 'offset');
        self::assertSame(['3', '4', '1', '2', '5', '6'], $this->walkCursor('referrerName,name'), 'cursor');
    }

    #[Test]
    public function aSortThroughARelationNoCallerMayReadIsForbidden(): void
    {
        // The related endpoint's own answer for the relation.
        $this->fetch('/api/thrLeads/4/sponsor')->assertForbidden();
        $this->fetch('/api/thrLeads?sort=sponsorName')->assertForbidden()->assertJsonPath('errors.0.code', 'ACCESS_DENIED');
        $this->fetch('/api/thrLeads?sort=sponsorName&page[kind]=cursor&page[size]=2')->assertForbidden();
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function windows(): iterable
    {
        yield 'read off the model' => ['pagedLeads'];
        yield 'read through the foreign key' => ['keyedLeads'];
    }

    #[Test]
    public function aCursorWindowReadsItsDefaultOrderAsNullThroughAForbiddenHop(): void
    {
        foreach (self::windows() as $case => [$relation]) {
            // Joan's sponsor is Grace, but nobody may read it, so both sort as null, by name.
            self::assertSame(['1', '4'], $this->windowIds($relation, ''), $case . ': include');
            self::assertSame(['1', '4'], $this->ids('/api/thrCampaigns/' . self::SPRING . '/' . $relation), $case . ': related');
        }
    }

    #[Test]
    public function aCursorWindowSortsThroughARelationTheRelatedQueryNames(): void
    {
        foreach (self::windows() as $case => [$relation]) {
            // Joan's referrer is Grace; Ada's is null, the largest value on a cursor page.
            self::assertSame(['4', '1'], $this->windowIds($relation, '&relatedQuery[' . $relation . '][sort]=referrerName,name'), $case);
            self::assertSame(['1', '4'], $this->windowIds($relation, '&relatedQuery[' . $relation . '][sort]=-referrerName,name'), $case . ': descending');
            $this->get('/api/thrCampaigns/' . self::SPRING . '?include=' . $relation . '&relatedQuery[' . $relation . '][sort]=sponsorName', ['Accept' => self::QUERIES])->assertForbidden();
        }
    }

    #[Test]
    public function aFilterDefaultReachesThroughAndARelationCanDropIt(): void
    {
        self::assertSame([self::SPRING], $this->ids('/api/thrCampaigns'));
        self::assertSame([self::SUMMER], $this->ids('/api/thrCampaigns?filter[advertiserName]=Globex'));
        self::assertSame([self::SUMMER], $this->ids('/api/thrAdvertisers/7/campaigns'));
        self::assertSame([], $this->ids('/api/thrAdvertisers/7/defaultCampaigns'));
    }

    #[Test]
    public function aRelatedEndpointAppliesThePathsOfTheRelatedType(): void
    {
        self::assertSame(['1'], $this->ids('/api/thrCampaigns/' . self::SPRING . '/leads?filter[templateName]=Welcome'));
        self::assertSame(['4', '1'], $this->ids('/api/thrCampaigns/' . self::SPRING . '/leads?sort=-name'));
        self::assertSame(['1'], $this->ids('/api/thrCampaigns/' . self::SPRING . '/leads?filter[templateGroup]=onboarding'));
    }

    #[Test]
    public function relationshipQueriesApplyAThroughFilterAndSort(): void
    {
        foreach (['templateName]=Survey' => ['4'], 'templateGroup]=feedback' => ['4'], 'templateGroup]=retired' => []] as $filter => $expected) {
            $response = $this->get('/api/thrCampaigns/' . self::SPRING . '?include=leads&relatedQuery[leads][filter][' . $filter, ['Accept' => self::QUERIES]);
            $response->assertOk();
            self::assertSame($expected, self::idsOf($response->json('data.relationships.leads.data')), $filter);
        }
    }

    /**
     * The ids in a spring campaign's `$relation` window, included with `$query` appended.
     *
     * @return list<string>
     */
    private function windowIds(string $relation, string $query): array
    {
        $uri = '/api/thrCampaigns/' . self::SPRING . '?include=' . $relation . $query;
        $response = $this->get($uri, ['Accept' => self::QUERIES]);
        self::assertSame(200, $response->getStatusCode(), $uri . ': ' . $response->getContent());

        return self::idsOf($response->json('data.relationships.' . $relation . '.data'));
    }

    /**
     * @return list<string>
     */
    private function walkCursor(string $sort): array
    {
        $ids = [];
        $next = '/api/thrLeads?sort=' . $sort . '&page[kind]=cursor&page[size]=2';
        $pages = 0;
        while ($next !== null) {
            self::assertLessThan(5, ++$pages, 'the walk ends');
            $response = $this->fetch($next);
            self::assertSame(200, $response->getStatusCode(), $next . ': ' . $response->getContent());
            $ids = [...$ids, ...self::idsOf($response->json('data'))];
            $link = $response->json('links.next');
            $href = \is_array($link) ? ($link['href'] ?? null) : $link;
            $next = \is_string($href) ? (string) \preg_replace('#^https?://[^/]+#', '', $href) : null;
        }

        return $ids;
    }

    /**
     * @return list<string>
     */
    protected function leadIds(string $query): array
    {
        $ids = $this->ids('/api/thrLeads?' . $query);
        \sort($ids);

        return $ids;
    }

    private function assertBadValue(string $query, string $parameter): void
    {
        $response = $this->fetch('/api/thrLeads?' . $query);
        self::assertSame(400, $response->getStatusCode(), (string) $response->getContent());
        $response->assertJsonPath('errors.0.code', 'FILTER_VALUE_INVALID');
        $response->assertJsonPath('errors.0.source.parameter', $parameter);
    }

    /**
     * @return list<string>
     */
    private function ids(string $uri): array
    {
        $response = $this->fetch($uri);
        self::assertSame(200, $response->getStatusCode(), $uri . ': ' . $response->getContent());

        return self::idsOf($response->json('data'));
    }

    /**
     * @return TestResponse<\Symfony\Component\HttpFoundation\Response>
     */
    private function fetch(string $uri): TestResponse
    {
        return $this->get($uri, ['Accept' => self::MEDIA_TYPE]);
    }

    /**
     * @return list<string>
     */
    private static function idsOf(mixed $data): array
    {
        self::assertIsArray($data);

        return \array_values(\array_map(static fn(mixed $resource): string => \is_array($resource) && \is_string($resource['id'] ?? null) ? $resource['id'] : '', $data));
    }
}
