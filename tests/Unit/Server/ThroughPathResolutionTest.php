<?php

declare(strict_types=1);

namespace haddowg\JsonApiLaravel\Tests\Unit\Server;

use haddowg\JsonApi\Server\ResourceRegistry;
use haddowg\JsonApiLaravel\DataProvider\DataProviderRegistry;
use haddowg\JsonApiLaravel\Server\FilterPathGuard;
use haddowg\JsonApiLaravel\Server\ThroughPathGuard;
use haddowg\JsonApiLaravel\Tests\Fixtures\ThroughRelations\ThrAdvertiserResource;
use haddowg\JsonApiLaravel\Tests\Fixtures\ThroughRelations\ThrBrokenResource;
use haddowg\JsonApiLaravel\Tests\Fixtures\ThroughRelations\ThrCampaignResource;
use haddowg\JsonApiLaravel\Tests\Fixtures\ThroughRelations\ThrLeadResource;
use haddowg\JsonApiLaravel\Tests\Fixtures\ThroughRelations\ThrTemplateResource;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * A filter or sort declared with `->through()` whose path can't be resolved fails route
 * registration or `jsonapi:optimize`, naming the type that declares it.
 *
 * @internal
 */
#[CoversClass(FilterPathGuard::class)]
#[CoversClass(ThroughPathGuard::class)]
final class ThroughPathResolutionTest extends TestCase
{
    protected function tearDown(): void
    {
        ThrBrokenResource::$declaration = 'storageName';
    }

    /**
     * @return iterable<string, array{string, string}>
     */
    public static function brokenDeclarations(): iterable
    {
        yield 'a relation named by its storage member' => ['storageName', '"campaign" is not a relation'];
        yield 'a column naming no field' => ['noField', 'declares no such field'];
        yield 'a value filter on a relation' => ['relationLeaf', 'is a relation'];
        yield 'a sort through a to-many' => ['toManySort', 'crosses a to-many'];
        yield 'an id filter keyed by a relation with another column' => ['ambiguousId', 'could mean the relation\'s ids or the type\'s own'];
    }

    #[Test]
    #[DataProvider('brokenDeclarations')]
    public function aPathThatCannotResolveFailsTheWarmUp(string $declaration, string $message): void
    {
        ThrBrokenResource::$declaration = $declaration;
        $registry = new ResourceRegistry();
        foreach ([ThrBrokenResource::class, ThrLeadResource::class, ThrCampaignResource::class, ThrAdvertiserResource::class, ThrTemplateResource::class] as $class) {
            $registry->register($class);
        }

        $problems = (new ThroughPathGuard(new DataProviderRegistry([])))->check($registry, 'default', new ThrBrokenResource());
        try {
            FilterPathGuard::check($registry, new ThrBrokenResource());
        } catch (\LogicException $e) {
            $problems[] = $e->getMessage();
        }

        self::assertCount(1, $problems);
        self::assertStringContainsString('type "thrBroken"', $problems[0]);
        self::assertStringContainsString($message, $problems[0]);
    }

    #[Test]
    public function everyDeclarationOfTheLeadsResolves(): void
    {
        $registry = new ResourceRegistry();
        foreach ([ThrLeadResource::class, ThrCampaignResource::class, ThrAdvertiserResource::class, ThrTemplateResource::class] as $class) {
            $registry->register($class);
        }

        foreach ([new ThrLeadResource(), new ThrCampaignResource(), new ThrAdvertiserResource()] as $resource) {
            FilterPathGuard::check($registry, $resource, []);
            self::assertSame([], (new ThroughPathGuard(new DataProviderRegistry([])))->check($registry, 'default', $resource));
        }
    }
}
