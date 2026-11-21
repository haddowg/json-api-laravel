<?php

declare(strict_types=1);

namespace haddowg\JsonApiLaravel\Tests\Feature\FirstPage;

use haddowg\JsonApi\Pagination\CursorPaginationProfile;
use haddowg\JsonApi\Schema\Profile\CountableProfile;
use haddowg\JsonApi\Schema\Profile\RelationshipQueriesProfile;
use haddowg\JsonApiLaravel\JsonApiServiceProvider;
use haddowg\JsonApiLaravel\Tests\Fixtures\FirstPage\FirstPageServiceProvider;
use Illuminate\Testing\TestResponse;
use Orchestra\Testbench\TestCase as Orchestra;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\Test;

/**
 * Wherever a paginated to-many's linkage renders, included or declared `withData()`, it is the
 * first page under the relation's paginator, `included` holds exactly that page, and the
 * relationship object links the rest. Asserted identically on the Eloquent and in-memory stores
 * over {@see \haddowg\JsonApiLaravel\Tests\Fixtures\FirstPage\FirstPageSeed}, the Symfony
 * bundle's twin suite.
 */
#[Group('spec:fetching-includes')]
#[Group('spec:fetching-pagination')]
abstract class FirstPageTestCase extends Orchestra
{
    protected const string BASE_URI = 'https://example.test';

    protected const string MEDIA_TYPE = 'application/vnd.api+json';

    private const string COUNTS_ACCEPT = 'application/vnd.api+json;profile="' . CountableProfile::URI . '"';

    private const string QUERIES_ACCEPT = 'application/vnd.api+json;profile="' . RelationshipQueriesProfile::URI . '"';

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
        $config->set('first_page.store', $this->store());
        $config->set('jsonapi.base_uri', self::BASE_URI);
        $config->set('jsonapi.servers.default.prefix', '');
        $config->set('jsonapi.atomic_operations.enabled', true);

        return [JsonApiServiceProvider::class, FirstPageServiceProvider::class];
    }

    #[Test]
    public function anIncludedToManyRendersItsFirstPage(): void
    {
        $document = $this->fetch('/fp-boards/1?include=cards');
        $cards = $this->relationship($document['data'] ?? null, 'cards');

        self::assertSame(['1', '2'], $this->linkageIds($cards));
        self::assertSame(['fp-cards:1', 'fp-cards:2'], $this->includedKeys($document));

        $links = $this->links($cards);
        self::assertSame(self::BASE_URI . '/fp-boards/1/relationships/cards?page%5Bnumber%5D=1&page%5Bsize%5D=2', $links['first'] ?? null);
        self::assertSame(self::BASE_URI . '/fp-boards/1/relationships/cards?page%5Bnumber%5D=2&page%5Bsize%5D=2', $links['next'] ?? null);
        self::assertArrayNotHasKey('last', $links);
        self::assertArrayNotHasKey('prev', $links);
        self::assertArrayNotHasKey('meta', $cards);
    }

    #[Test]
    public function theNextLinkReachesTheSecondPage(): void
    {
        $cards = $this->relationship($this->fetch('/fp-boards/1?include=cards')['data'] ?? null, 'cards');
        $next = $this->links($cards)['next'] ?? null;
        self::assertIsString($next);

        $page = $this->fetch(\substr($next, \strlen(self::BASE_URI)));
        self::assertSame(['3', '4'], $this->ids($page['data'] ?? null));
    }

    #[Test]
    public function aCollectionIncludeCutsEachParentsOwnFirstPage(): void
    {
        $document = $this->fetch('/fp-boards?include=cards');
        $boards = $document['data'] ?? null;
        self::assertIsArray($boards);

        $roadmap = $this->relationship($boards[0] ?? null, 'cards');
        self::assertSame(['1', '2'], $this->linkageIds($roadmap));
        self::assertArrayHasKey('next', $this->links($roadmap));

        $backlog = $this->relationship($boards[1] ?? null, 'cards');
        self::assertSame(['6'], $this->linkageIds($backlog));
        self::assertArrayNotHasKey('next', $this->links($backlog));

        self::assertSame(['fp-cards:1', 'fp-cards:2', 'fp-cards:6'], $this->includedKeys($document));
    }

    #[Test]
    public function aCountedFirstPageCarriesItsTotalAndLastLink(): void
    {
        $cards = $this->relationship($this->fetch('/fp-boards/1?include=cards&withCount=cards', self::COUNTS_ACCEPT)['data'] ?? null, 'cards');

        self::assertSame(['1', '2'], $this->linkageIds($cards));
        self::assertSame(['total' => 5], $cards['meta'] ?? null);
        $links = $this->links($cards);
        self::assertSame(self::BASE_URI . '/fp-boards/1/relationships/cards?withCount=_self_&page%5Bnumber%5D=3&page%5Bsize%5D=2', $links['last'] ?? null);

        // Following `next` stays counted: the second page links its last page too.
        $next = $links['next'] ?? null;
        self::assertIsString($next);
        $page = $this->fetch(\substr($next, \strlen(self::BASE_URI)), self::COUNTS_ACCEPT);
        self::assertSame(['3', '4'], $this->ids($page['data'] ?? null));
        $pageLinks = $this->links($page);
        self::assertArrayHasKey('last', $pageLinks);
        self::assertStringContainsString('withCount=_self_', $pageLinks['next'] ?? '');
    }

    #[Test]
    public function aCountedRelatedQueryPageKeepsBothInItsLinks(): void
    {
        $accept = 'application/vnd.api+json;profile="' . RelationshipQueriesProfile::URI . ' ' . CountableProfile::URI . '"';
        $cards = $this->relationship($this->fetch('/fp-boards/1?include=cards&withCount=cards&relatedQuery[cards][sort]=-title', $accept)['data'] ?? null, 'cards');

        self::assertSame(['2', '3'], $this->linkageIds($cards));
        self::assertSame(self::BASE_URI . '/fp-boards/1/relationships/cards?sort=-title&withCount=_self_&page%5Bnumber%5D=2&page%5Bsize%5D=2', $this->links($cards)['next'] ?? null);
    }

    /**
     * `featured` is declared `notSparseField()`, so a fieldset leaving it out still
     * renders it, cut to its first page as it is without one.
     */
    #[Test]
    public function aNonSparseToManyRendersItsFirstPageWhenTheFieldsetLeavesItOut(): void
    {
        $document = $this->fetch('/fp-boards/1?fields[fp-boards]=name');
        $data = $document['data'] ?? null;
        self::assertIsArray($data);
        self::assertIsArray($data['relationships'] ?? null);
        self::assertSame(['featured'], \array_keys($data['relationships']));

        $featured = $this->relationship($data, 'featured');
        self::assertSame(['1', '2'], $this->linkageIds($featured));
        self::assertSame(self::BASE_URI . '/fp-boards/1/relationships/featured?page%5Bnumber%5D=2&page%5Bsize%5D=2', $this->links($featured)['next'] ?? null);
    }

    #[Test]
    public function aWithDataToManyRendersItsFirstPageWithoutBeingIncluded(): void
    {
        $document = $this->fetch('/fp-boards/1');
        $featured = $this->relationship($document['data'] ?? null, 'featured');

        self::assertSame(['1', '2'], $this->linkageIds($featured));
        self::assertSame(self::BASE_URI . '/fp-boards/1/relationships/featured?page%5Bnumber%5D=2&page%5Bsize%5D=2', $this->links($featured)['next'] ?? null);
        self::assertArrayNotHasKey('included', $document);
    }

    #[Test]
    public function aManyToManyRendersItsFirstPage(): void
    {
        $document = $this->fetch('/fp-boards/1?include=labels');
        $labels = $this->relationship($document['data'] ?? null, 'labels');

        self::assertSame(['1', '2'], $this->linkageIds($labels));
        self::assertSame(['fp-labels:1', 'fp-labels:2'], $this->includedKeys($document));
        self::assertArrayHasKey('next', $this->links($labels));
    }

    #[Test]
    public function aPolymorphicToManyRendersItsFirstPageAndLinksTheNext(): void
    {
        $document = $this->fetch('/fp-boards/1?include=pins');
        $pins = $this->relationship($document['data'] ?? null, 'pins');

        self::assertSame(['fp-labels:1', 'fp-labels:2'], $this->linkageKeys($pins));
        self::assertSame(['fp-labels:1', 'fp-labels:2'], $this->includedKeys($document));

        $next = $this->links($pins)['next'] ?? null;
        self::assertIsString($next);
        self::assertSame(['fp-labels:3', 'fp-cards:1'], $this->linkageKeys($this->fetch(\substr($next, \strlen(self::BASE_URI)))));
    }

    #[Test]
    public function anIdentifiedMorphToManyRendersItsFirstPageAndLinksTheNext(): void
    {
        $document = $this->fetch('/fp-boards/1?include=tagged');
        $tagged = $this->relationship($document['data'] ?? null, 'tagged');

        self::assertSame(['fp-labels:3', 'fp-cards:2'], $this->linkageKeys($tagged));
        self::assertSame(['fp-cards:2', 'fp-labels:3'], $this->includedKeys($document));

        $next = $this->links($tagged)['next'] ?? null;
        self::assertIsString($next);
        self::assertSame(['fp-labels:1', 'fp-cards:5'], $this->linkageKeys($this->fetch(\substr($next, \strlen(self::BASE_URI)))));
    }

    #[Test]
    public function anIdentifiedMorphToManysLinkageAloneIsItsFirstPage(): void
    {
        $tagged = $this->relationship($this->fetch('/fp-boards/1?fields[fp-boards]=tagged')['data'] ?? null, 'tagged');

        self::assertSame(['fp-labels:3', 'fp-cards:2'], $this->linkageKeys($tagged));
        self::assertArrayHasKey('next', $this->links($tagged));
    }

    #[Test]
    public function aRelationWithoutPaginationIncludesItsWholeSet(): void
    {
        $document = $this->fetch('/fp-boards/1?include=allCards');
        $cards = $this->relationship($document['data'] ?? null, 'allCards');

        self::assertSame(['1', '2', '3', '4', '5'], $this->linkageIds($cards));
        self::assertCount(5, $this->includedKeys($document));
        self::assertArrayNotHasKey('first', $this->links($cards));
        self::assertArrayNotHasKey('next', $this->links($cards));
    }

    #[Test]
    public function aRelationWithNoEndpointIncludesItsWholeSet(): void
    {
        $document = $this->fetch('/fp-boards/1?include=pocketCards');
        $cards = $this->relationship($document['data'] ?? null, 'pocketCards');

        self::assertSame(['1', '2', '3', '4', '5'], $this->linkageIds($cards));
        self::assertArrayNotHasKey('links', $cards);
    }

    #[Test]
    public function aNestedIncludeCutsEachLevelUnderItsOwnPaginator(): void
    {
        $document = $this->fetch('/fp-boards/1?include=cards.comments');
        $cards = $this->relationship($document['data'] ?? null, 'cards');
        self::assertSame(['1', '2'], $this->linkageIds($cards));

        $included = $this->includedByKey($document);
        $first = $this->relationship($included['fp-cards:1'] ?? null, 'comments');
        self::assertSame(['1'], $this->linkageIds($first));
        self::assertSame(self::BASE_URI . '/fp-cards/1/relationships/comments?page%5Bnumber%5D=2&page%5Bsize%5D=1', $this->links($first)['next'] ?? null);

        $second = $this->relationship($included['fp-cards:2'] ?? null, 'comments');
        self::assertSame(['4'], $this->linkageIds($second));
        self::assertArrayNotHasKey('next', $this->links($second));

        self::assertSame(['fp-cards:1', 'fp-cards:2', 'fp-comments:1', 'fp-comments:4'], $this->includedKeys($document));
    }

    #[Test]
    public function aSingletonsIncludeRendersItsFirstCursorPageInItsDefaultOrder(): void
    {
        $response = $this->get('/desk?include=cards', ['Accept' => self::MEDIA_TYPE]);
        self::assertSame(200, $response->getStatusCode(), (string) $response->getContent());
        $document = $this->decode($response);

        $cards = $this->relationship($document['data'] ?? null, 'cards');
        self::assertSame(['3', '5'], $this->linkageIds($cards));
        self::assertSame(['fp-cards:3', 'fp-cards:5'], $this->includedKeys($document));

        $links = $this->links($cards);
        self::assertArrayNotHasKey('prev', $links);
        self::assertArrayNotHasKey('last', $links);
        $next = $links['next'] ?? null;
        self::assertIsString($next);
        self::assertStringStartsWith(self::BASE_URI . '/desk/relationships/cards?', $next);
        self::assertStringContainsString('page%5Bafter%5D=', $next);

        // The page is a cursor page, so the document advertises the cursor profile.
        self::assertStringContainsString(CursorPaginationProfile::URI, (string) $response->headers->get('Content-Type'));

        $page = $this->fetch(\substr($next, \strlen(self::BASE_URI)));
        self::assertSame(['1', '4'], $this->ids($page['data'] ?? null));
    }

    #[Test]
    public function aRelatedCollectionsIncludesRenderTheirFirstPages(): void
    {
        $document = $this->fetch('/fp-boards/1/cards?include=comments');
        $cards = $document['data'] ?? null;
        self::assertIsArray($cards);
        self::assertSame(['1', '2'], $this->ids($cards));

        self::assertSame(['1'], $this->linkageIds($this->relationship($cards[0] ?? null, 'comments')));
        self::assertSame(['4'], $this->linkageIds($this->relationship($cards[1] ?? null, 'comments')));
        self::assertSame(['fp-comments:1', 'fp-comments:4'], $this->includedKeys($document));
    }

    #[Test]
    public function anAtomicResultRendersItsWithDataFirstPage(): void
    {
        $ext = 'application/vnd.api+json; ext="https://jsonapi.org/ext/atomic"';
        $content = (string) \json_encode(['atomic:operations' => [[
            'op' => 'update',
            'ref' => ['type' => 'fp-boards', 'id' => '1'],
            'data' => ['type' => 'fp-boards', 'id' => '1', 'attributes' => ['name' => 'Renamed']],
        ]]], \JSON_THROW_ON_ERROR);

        $response = $this->call('POST', '/operations', [], [], [], ['CONTENT_TYPE' => $ext, 'HTTP_ACCEPT' => $ext], $content);
        self::assertSame(200, $response->getStatusCode(), (string) $response->getContent());

        $results = $this->decode($response)['atomic:results'] ?? null;
        self::assertIsArray($results);
        $result = $results[0] ?? null;
        self::assertIsArray($result);

        $featured = $this->relationship($result['data'] ?? null, 'featured');
        self::assertSame(['1', '2'], $this->linkageIds($featured));
        self::assertArrayHasKey('next', $this->links($featured));
    }

    #[Test]
    public function aRelatedQueryStillOrdersTheFirstPage(): void
    {
        $document = $this->fetch('/fp-boards/1?include=cards&relatedQuery[cards][sort]=-title', self::QUERIES_ACCEPT);
        $cards = $this->relationship($document['data'] ?? null, 'cards');

        // Two, Three, One, Four, Five, descending by title.
        self::assertSame(['2', '3'], $this->linkageIds($cards));
        self::assertSame(['fp-cards:2', 'fp-cards:3'], $this->includedKeys($document));
        self::assertSame(self::BASE_URI . '/fp-boards/1/relationships/cards?sort=-title&page%5Bnumber%5D=2&page%5Bsize%5D=2', $this->links($cards)['next'] ?? null);
    }

    /**
     * @return array<string, mixed>
     */
    protected function fetch(string $path, ?string $accept = null): array
    {
        $response = $this->get($path, ['Accept' => $accept ?? self::MEDIA_TYPE]);
        self::assertSame(200, $response->getStatusCode(), (string) $response->getContent());

        return $this->decode($response);
    }

    /**
     * @param TestResponse<\Symfony\Component\HttpFoundation\Response> $response
     *
     * @return array<string, mixed>
     */
    protected function decode(TestResponse $response): array
    {
        $document = \json_decode((string) $response->getContent(), true, 512, \JSON_THROW_ON_ERROR);
        self::assertIsArray($document);

        /** @var array<string, mixed> $document */
        return $document;
    }

    /**
     * @return array<string, mixed>
     */
    private function relationship(mixed $resource, string $name): array
    {
        self::assertIsArray($resource);
        $relationships = $resource['relationships'] ?? null;
        self::assertIsArray($relationships);
        $relationship = $relationships[$name] ?? null;
        self::assertIsArray($relationship, $name . ' is rendered');

        /** @var array<string, mixed> $relationship */
        return $relationship;
    }

    /**
     * @param array<string, mixed> $relationship
     *
     * @return list<string>
     */
    private function linkageIds(array $relationship): array
    {
        self::assertArrayHasKey('data', $relationship);

        return $this->ids($relationship['data']);
    }

    /**
     * @param array<string, mixed> $document a relationship object or a linkage document
     *
     * @return list<string>
     */
    private function linkageKeys(array $document): array
    {
        $data = $document['data'] ?? null;
        self::assertIsArray($data);
        $keys = [];
        foreach ($data as $identifier) {
            self::assertIsArray($identifier);
            $type = $identifier['type'] ?? null;
            $id = $identifier['id'] ?? null;
            self::assertIsString($type);
            self::assertIsString($id);
            $keys[] = $type . ':' . $id;
        }

        return $keys;
    }

    /**
     * @return list<string>
     */
    private function ids(mixed $data): array
    {
        self::assertIsArray($data);
        $ids = [];
        foreach ($data as $item) {
            self::assertIsArray($item);
            $id = $item['id'] ?? null;
            self::assertIsString($id);
            $ids[] = $id;
        }

        return $ids;
    }

    /**
     * @param array<string, mixed> $relationship
     *
     * @return array<string, string>
     */
    private function links(array $relationship): array
    {
        $links = $relationship['links'] ?? [];
        self::assertIsArray($links);

        $hrefs = [];
        foreach ($links as $name => $link) {
            $href = \is_array($link) ? ($link['href'] ?? null) : $link;
            self::assertIsString($href);
            $hrefs[(string) $name] = $href;
        }

        return $hrefs;
    }

    /**
     * @param array<string, mixed> $document
     *
     * @return array<string, array<string, mixed>>
     */
    private function includedByKey(array $document): array
    {
        $included = $document['included'] ?? [];
        self::assertIsArray($included);
        $byKey = [];
        foreach ($included as $resource) {
            self::assertIsArray($resource);
            $type = $resource['type'] ?? null;
            $id = $resource['id'] ?? null;
            self::assertIsString($type);
            self::assertIsString($id);
            /** @var array<string, mixed> $resource */
            $byKey[$type . ':' . $id] = $resource;
        }

        return $byKey;
    }

    /**
     * @param array<string, mixed> $document
     *
     * @return list<string>
     */
    private function includedKeys(array $document): array
    {
        $keys = \array_keys($this->includedByKey($document));
        \sort($keys);

        return $keys;
    }
}
