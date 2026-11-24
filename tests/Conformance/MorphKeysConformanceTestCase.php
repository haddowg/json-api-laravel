<?php

declare(strict_types=1);

namespace haddowg\JsonApiLaravel\Tests\Conformance;

use haddowg\JsonApiLaravel\JsonApiServiceProvider;
use haddowg\JsonApiLaravel\Tests\Fixtures\MorphKeys\MorphKeysServiceProvider;
use Illuminate\Testing\TestResponse;
use Orchestra\Testbench\TestCase as Orchestra;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\Test;

/**
 * A polymorphic id stored as the target's storage key or its wire id, for a plain integer
 * id, an encoded one and an encoded one no scope narrows, asserted identically on the
 * in-memory witness ({@see InMemoryMorphKeysConformanceTest}) and the reference Eloquent
 * provider ({@see EloquentMorphKeysConformanceTest}) over the rows
 * {@see MorphKeysServiceProvider} seeds. The filter, the linkage, the related read and the
 * include agree on every row.
 */
abstract class MorphKeysConformanceTestCase extends Orchestra
{
    public const string MEDIA_TYPE = 'application/vnd.api+json';

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
        $config->set('morph_keys.store', $this->store());

        return [JsonApiServiceProvider::class, MorphKeysServiceProvider::class];
    }

    #[Test]
    #[Group('spec:fetching-filtering')]
    public function aMorphToMatchesAPlainIdTheScopeAdmits(): void
    {
        self::assertSame(['1'], $this->noteIds('filter[subject][type]=mkCounters'));
        self::assertSame(['1'], $this->noteIds('filter[subject][type]=mkCounters&filter[subject][id]=1'));
        self::assertSame([], $this->noteIds('filter[subject][type]=mkCounters&filter[subject][id]=2'));
    }

    #[Test]
    #[Group('spec:fetching-filtering')]
    public function aMorphToMatchesAnEncodedIdStoredInEitherForm(): void
    {
        self::assertSame(['3', '4'], $this->noteIds('filter[subject][type]=mkBadges'));
        self::assertSame(['3'], $this->noteIds('filter[subject][type]=mkBadges&filter[subject][id]=b-1'));
        self::assertSame(['4'], $this->noteIds('filter[subject][type]=mkBadges&filter[subject][id]=b-2'));
        self::assertSame(['3', '4'], $this->noteIds('filter[subject][type]=mkBadges&filter[subject][id]=b-1,b-2,b-3,b-4'));
        self::assertSame([], $this->noteIds('filter[subject][type]=mkBadges&filter[subject][id]=b-3,b-4'));
    }

    #[Test]
    #[Group('spec:fetching-filtering')]
    public function aMorphToMatchesAStringKeyTheScopeAdmits(): void
    {
        self::assertSame(['10'], $this->noteIds('filter[subject][type]=mkCodes'));
        self::assertSame(['10'], $this->noteIds('filter[subject][type]=mkCodes&filter[subject][id]=alpha'));
        self::assertSame([], $this->noteIds('filter[subject][type]=mkCodes&filter[subject][id]=beta'));
    }

    #[Test]
    #[Group('spec:fetching-filtering')]
    public function anUnscopedTypeMatchesEitherFormToo(): void
    {
        self::assertSame(['7', '8'], $this->noteIds('filter[subject][type]=mkTokens'));
        self::assertSame(['7'], $this->noteIds('filter[subject][type]=mkTokens&filter[subject][id]=t-1'));
        self::assertSame(['8'], $this->noteIds('filter[subject][type]=mkTokens&filter[subject][id]=t-2'));
    }

    #[Test]
    #[Group('spec:fetching-filtering')]
    public function aMorphToManyMatchesALinkRowInEitherForm(): void
    {
        self::assertSame(['1', '4'], $this->noteIds('filter[pinned][type]=mkBadges'));
        self::assertSame(['1'], $this->noteIds('filter[pinned][type]=mkBadges&filter[pinned][id]=b-2'));
        self::assertSame(['4'], $this->noteIds('filter[pinned][type]=mkBadges&filter[pinned][id]=b-1'));
        self::assertSame([], $this->noteIds('filter[pinned][type]=mkBadges&filter[pinned][id]=b-3'));
        self::assertSame(['3'], $this->noteIds('filter[pinned][type]=mkCounters'));
        self::assertSame(['7'], $this->noteIds('filter[pinned][type]=mkTokens&filter[pinned][id]=t-2'));
    }

    #[Test]
    #[Group('spec:fetching-filtering')]
    public function theLinkageRendersTheWireIdWhicheverFormIsStored(): void
    {
        $notes = $this->request('/api/mkNotes');
        $notes->assertOk();
        $linkage = [];
        foreach ((array) $notes->json('data') as $note) {
            self::assertIsArray($note);
            $subject = $note['relationships'] ?? null;
            self::assertIsArray($subject);
            $subject = $subject['subject'] ?? null;
            self::assertIsArray($subject);
            $data = $subject['data'] ?? null;
            if (\is_array($data)) {
                $parts = [$note['id'] ?? null, $data['type'] ?? null, $data['id'] ?? null];
                self::assertContainsOnlyString($parts);
                $linkage[] = \implode(':', $parts);
            }
        }
        \sort($linkage);

        // A hidden target renders as data: null, as for any guarded to-one.
        self::assertSame(['10:mkCodes:alpha', '1:mkCounters:1', '3:mkBadges:b-1', '4:mkBadges:b-2', '7:mkTokens:t-1', '8:mkTokens:t-2'], $linkage);
    }

    #[Test]
    #[Group('spec:fetching-relationships')]
    public function theRelatedReadAgreesWithTheFilter(): void
    {
        self::assertSame('mkCounters:1:one', $this->related(1));
        self::assertNull($this->related(2));
        self::assertSame('mkBadges:b-1:gold', $this->related(3));
        self::assertSame('mkBadges:b-2:silver', $this->related(4));
        self::assertNull($this->related(5));
        self::assertNull($this->related(6));
        self::assertSame('mkTokens:t-1:red', $this->related(7));
        self::assertSame('mkTokens:t-2:blue', $this->related(8));
    }

    #[Test]
    #[Group('spec:fetching-includes')]
    public function theIncludeAgreesWithTheFilter(): void
    {
        self::assertSame(['mkBadges:b-1', 'mkBadges:b-2'], $this->included('/api/mkNotes?include=subject&filter[subject][type]=mkBadges'));
        self::assertSame(['mkBadges:b-1', 'mkBadges:b-2', 'mkCodes:alpha', 'mkCounters:1', 'mkTokens:t-1', 'mkTokens:t-2'], $this->included('/api/mkNotes?include=subject'));
    }

    /**
     * @return TestResponse<\Symfony\Component\HttpFoundation\Response>
     */
    protected function request(string $path): TestResponse
    {
        return $this->get($path, ['Accept' => self::MEDIA_TYPE]);
    }

    /**
     * @return list<string>
     */
    private function noteIds(string $query): array
    {
        $response = $this->request('/api/mkNotes?' . $query);
        $response->assertOk();

        $ids = [];
        foreach ((array) $response->json('data') as $resource) {
            self::assertIsArray($resource);
            $id = $resource['id'] ?? null;
            self::assertIsString($id);
            $ids[] = $id;
        }
        \sort($ids);

        return $ids;
    }

    /**
     * The note's related subject as `type:id:name`, or `null` when there is none.
     */
    private function related(int $note): ?string
    {
        $response = $this->request('/api/mkNotes/' . $note . '/subject');
        $response->assertOk();
        $data = $response->json('data');
        if ($data === null) {
            return null;
        }
        self::assertIsArray($data);
        $attributes = $data['attributes'] ?? null;
        self::assertIsArray($attributes);
        $parts = [$data['type'] ?? null, $data['id'] ?? null, $attributes['name'] ?? null];
        self::assertContainsOnlyString($parts);

        return \implode(':', $parts);
    }

    /**
     * @return list<string>
     */
    private function included(string $path): array
    {
        $response = $this->request($path);
        $response->assertOk();

        $keys = [];
        foreach ((array) $response->json('included') as $resource) {
            self::assertIsArray($resource);
            $keys[] = (\is_string($resource['type'] ?? null) ? $resource['type'] : '') . ':' . (\is_string($resource['id'] ?? null) ? $resource['id'] : '');
        }
        \sort($keys);

        return $keys;
    }
}
