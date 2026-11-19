<?php

declare(strict_types=1);

namespace haddowg\JsonApiLaravel\Tests\Unit\DataProvider;

use haddowg\JsonApi\Operation\QueryParameters;
use haddowg\JsonApi\Request\JsonApiRequestInterface;
use haddowg\JsonApi\Resource\Field\BelongsTo;
use haddowg\JsonApi\Resource\Field\FieldInterface;
use haddowg\JsonApi\Resource\Field\Id;
use haddowg\JsonApi\Resource\Field\RelationInterface;
use haddowg\JsonApi\Resource\Field\Str;
use haddowg\JsonApi\Resource\Filter\FilterHandlerInterface;
use haddowg\JsonApi\Resource\Filter\FilterInterface;
use haddowg\JsonApi\Resource\Filter\HasDefaultValue;
use haddowg\JsonApi\Resource\Filter\InMemory\ArrayFilterHandler;
use haddowg\JsonApi\Resource\Filter\RelationHop;
use haddowg\JsonApi\Resource\Filter\ThroughPath;
use haddowg\JsonApi\Resource\Filter\ThroughPathResolver;
use haddowg\JsonApi\Resource\Filter\ThroughReadAccess;
use haddowg\JsonApi\Resource\Filter\TraversesPath;
use haddowg\JsonApi\Resource\Filter\WhereThrough;
use haddowg\JsonApi\Resource\ThroughReadGateInterface;
use haddowg\JsonApiLaravel\DataProvider\CollectionCriteria;
use haddowg\JsonApiLaravel\DataProvider\CriteriaApplier;
use haddowg\JsonApiLaravel\DataProvider\FilterPaths;
use haddowg\JsonApiLaravel\DataProvider\ScopedArrayFilterHandler;
use haddowg\JsonApiLaravel\DataProvider\ScopesFilterHopsInterface;
use Illuminate\Auth\Access\AuthorizationException;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * A filter reaching through a relation the caller may not read: refused when the client asks
 * for it, and matching no row through that relation when it is a default the client did not
 * ask for. No built-in traversal declares a default, so a stand-in does.
 *
 * @internal
 */
#[CoversClass(CriteriaApplier::class)]
#[CoversClass(FilterPaths::class)]
#[CoversClass(ScopedArrayFilterHandler::class)]
final class ThroughFilterDefaultsTest extends TestCase
{
    #[Test]
    public function aDefaultThroughAForbiddenRelationMatchesNothingThroughIt(): void
    {
        $handler = $this->recorder();
        (new CriteriaApplier())->applyFilters($this->criteria([], refuse: true), [], $handler);

        self::assertSame([true], $handler->refused);
    }

    #[Test]
    public function aDefaultThroughAReadableRelationRunsAsDeclared(): void
    {
        $handler = $this->recorder();
        (new CriteriaApplier())->applyFilters($this->criteria([], refuse: false), [], $handler);

        self::assertSame([false], $handler->refused);
    }

    #[Test]
    public function theSameFilterAskedForByTheClientIsForbidden(): void
    {
        $this->expectException(AuthorizationException::class);

        (new CriteriaApplier())->applyFilters($this->criteria(['vaultCode' => 'v1'], refuse: true), [], $this->recorder());
    }

    #[Test]
    public function theInMemoryWalkAdmitsNoRowThroughARefusedRelation(): void
    {
        $rows = [(object) ['id' => 1, 'vault' => (object) ['id' => 1, 'code' => 'v1']]];
        $paths = $this->paths(false);
        $filter = $paths->resolve(WhereThrough::make('vaultCode', 'vault.code')->build());
        $vault = $paths->hopAt('vault')?->relation;
        self::assertInstanceOf(RelationInterface::class, $vault);
        $handler = new ScopedArrayFilterHandler(new ArrayFilterHandler());

        self::assertSame([], $handler->forPaths($paths->scoped([$vault]))->apply($filter, $rows, 'v1'));
        self::assertSame($rows, $handler->forPaths($paths->scoped())->apply($filter, $rows, 'v1'));
    }

    /**
     * @param array<string, mixed> $requested
     */
    private function criteria(array $requested, bool $refuse): CollectionCriteria
    {
        // A traversal through `vault.code` that defaults to `v1`.
        $filter = new class implements TraversesPath, HasDefaultValue {
            public function key(): string
            {
                return 'vaultCode';
            }

            public function constraints(): array
            {
                return [];
            }

            public function throughPath(): string
            {
                return 'vault.code';
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
                return 'v1';
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
            'crates' => self::crates(),
            'vaults' => [Id::make()->build(), Str::make('code')->build()],
        ];
        $resolver = new ThroughPathResolver(static fn(string $type): ?array => $inventories[$type] ?? null);
        $gate = new class ($refuse) implements ThroughReadGateInterface {
            public function __construct(private readonly bool $refuse) {}

            public function canReadThrough(RelationHop $hop, JsonApiRequestInterface $request): bool
            {
                return !$this->refuse;
            }
        };

        return new FilterPaths($resolver, self::crates(), 'crates', access: new ThroughReadAccess($resolver, $gate));
    }

    /**
     * @return list<FieldInterface>
     */
    private static function crates(): array
    {
        return [Id::make()->build(), BelongsTo::make('vault', 'vaults')->build()];
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
                $vault = $this->paths?->hopAt('vault')?->relation;
                $this->refused[] = $vault !== null && $this->paths?->refuses($vault) === true;

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
