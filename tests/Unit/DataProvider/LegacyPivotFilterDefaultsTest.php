<?php

declare(strict_types=1);

namespace haddowg\JsonApiLaravel\Tests\Unit\DataProvider;

use haddowg\JsonApi\Operation\QueryParameters;
use haddowg\JsonApi\Request\JsonApiRequestInterface;
use haddowg\JsonApi\Resource\Field\BelongsTo;
use haddowg\JsonApi\Resource\Field\BelongsToMany;
use haddowg\JsonApi\Resource\Field\FieldInterface;
use haddowg\JsonApi\Resource\Field\Id;
use haddowg\JsonApi\Resource\Field\Integer;
use haddowg\JsonApi\Resource\Field\PivotRelationInterface;
use haddowg\JsonApi\Resource\Field\RelationInterface;
use haddowg\JsonApi\Resource\Field\Str;
use haddowg\JsonApi\Resource\Filter\FilterHandlerInterface;
use haddowg\JsonApi\Resource\Filter\FilterInterface;
use haddowg\JsonApi\Resource\Filter\HasDefaultValue;
use haddowg\JsonApi\Resource\Filter\RelationHop;
use haddowg\JsonApi\Resource\Filter\ThroughPath;
use haddowg\JsonApi\Resource\Filter\ThroughPathResolver;
use haddowg\JsonApi\Resource\Filter\ThroughReadAccess;
use haddowg\JsonApi\Resource\Filter\TraversesPath;
use haddowg\JsonApi\Resource\Through\PivotRowRelations;
use haddowg\JsonApi\Resource\ThroughReadGateInterface;
use haddowg\JsonApiLaravel\DataProvider\CollectionCriteria;
use haddowg\JsonApiLaravel\DataProvider\CriteriaApplier;
use haddowg\JsonApiLaravel\DataProvider\FilterPaths;
use haddowg\JsonApiLaravel\DataProvider\ScopesFilterHopsInterface;
use Illuminate\Auth\Access\AuthorizationException;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * A deprecated `pivot.`-prefixed traversal among a relation's filters reaching through a
 * relation of the pivot row the caller may not read: refused when the client asks for it,
 * and matching no row through that relation as a default. No built-in traversal declares a
 * default, so a stand-in does.
 *
 * @internal
 */
#[CoversClass(CriteriaApplier::class)]
#[CoversClass(FilterPaths::class)]
final class LegacyPivotFilterDefaultsTest extends TestCase
{
    private static ?RelationInterface $auditor = null;

    #[Test]
    public function aDefaultThroughARefusedPivotRowRelationMatchesNothingThroughIt(): void
    {
        $handler = $this->recorder();
        (new CriteriaApplier())->applyFilters($this->criteria([], refuse: true), [], $handler);

        self::assertSame([true], $handler->refused);
    }

    #[Test]
    public function aDefaultThroughAReadablePivotRowRelationRunsAsDeclared(): void
    {
        $handler = $this->recorder();
        (new CriteriaApplier())->applyFilters($this->criteria([], refuse: false), [], $handler);

        self::assertSame([false], $handler->refused);
    }

    #[Test]
    public function theSameFilterAskedForByTheClientIsForbidden(): void
    {
        $this->expectException(AuthorizationException::class);

        (new CriteriaApplier())->applyFilters($this->criteria(['auditedBy' => 'audrey'], refuse: true), [], $this->recorder());
    }

    /**
     * @param array<string, mixed> $requested
     */
    private function criteria(array $requested, bool $refuse): CollectionCriteria
    {
        // A deprecated traversal through the pivot row's auditor that defaults to `audrey`.
        $filter = new class implements TraversesPath, HasDefaultValue {
            public function key(): string
            {
                return 'auditedBy';
            }

            public function constraints(): array
            {
                return [];
            }

            public function throughPath(): string
            {
                return 'pivot.auditor.name';
            }

            public function resolvedPath(): ?ThroughPath
            {
                return null;
            }

            public function withResolvedPath(ThroughPath $path): static
            {
                return $this;
            }

            public function hasDefault(): bool
            {
                return true;
            }

            public function defaultValue(): mixed
            {
                return 'audrey';
            }
        };

        return new CollectionCriteria(
            new QueryParameters(fields: [], includes: [], sort: [], filter: $requested, pagination: []),
            [$filter],
            request: $this->createStub(JsonApiRequestInterface::class),
            paths: $this->paths($refuse),
        );
    }

    private function paths(bool $refuse): FilterPaths
    {
        $inventories = [
            'tracks' => self::tracks(),
            'playlists' => [Id::make()->build(), Str::make('name')->build()],
            'auditors' => [Id::make()->build(), Str::make('name')->build()],
        ];
        $rows = new class implements PivotRowRelations {
            public function pivotRowRelation(PivotRelationInterface $relation, string $parentType, string $member): ?RelationInterface
            {
                return $member === 'auditor' ? LegacyPivotFilterDefaultsTest::auditor() : null;
            }
        };
        $resolver = new ThroughPathResolver(static fn(string $type): ?array => $inventories[$type] ?? null, $rows);
        $gate = new class ($refuse) implements ThroughReadGateInterface {
            public function __construct(private readonly bool $refuse) {}

            public function canReadThrough(RelationHop $hop, JsonApiRequestInterface $request): bool
            {
                return !$this->refuse || $hop->relation !== LegacyPivotFilterDefaultsTest::auditor();
            }
        };
        $playlists = self::tracks()[1];
        \assert($playlists instanceof BelongsToMany);

        return new FilterPaths($resolver, $inventories['playlists'], 'playlists', access: new ThroughReadAccess($resolver, $gate), under: new RelationHop($playlists, 'tracks', 'playlists'));
    }

    public static function auditor(): RelationInterface
    {
        return self::$auditor ??= BelongsTo::make('auditor', 'auditors')->build();
    }

    /**
     * @return list<FieldInterface>
     */
    private static function tracks(): array
    {
        static $tracks = null;

        return $tracks ??= [Id::make()->build(), BelongsToMany::make('playlists', 'playlists')->fields(Integer::make('position'))->build()];
    }

    /**
     * @return FilterHandlerInterface<mixed>&ScopesFilterHopsInterface&object{refused: list<bool>}
     */
    private function recorder(): object
    {
        return new class implements FilterHandlerInterface, ScopesFilterHopsInterface {
            /**
             * @var list<bool>
             */
            public array $refused = [];

            private ?FilterPaths $paths = null;

            public function apply(FilterInterface $filter, mixed $query, mixed $value): mixed
            {
                $this->refused[] = $this->paths?->refuses(LegacyPivotFilterDefaultsTest::auditor()) === true;

                return $query;
            }

            public function forPaths(FilterPaths $paths): static
            {
                $this->paths = $paths;

                return $this;
            }
        };
    }
}
