<?php

declare(strict_types=1);

namespace haddowg\JsonApiLaravel\Tests\Fixtures\Scopes;

use haddowg\JsonApi\OpenApi\Metadata\OperationKey;
use haddowg\JsonApi\OpenApi\Metadata\OperationKind;
use haddowg\JsonApi\OpenApi\Metadata\TypeMetadataInterface;
use haddowg\JsonApi\Scope\OperationScopeResolverInterface;
use haddowg\JsonApi\Scope\ScopeCatalogInterface;
use Workbench\App\MusicCatalog\Security\CatalogScopes;

/**
 * The music catalogue's scopes plus `tracks:read` on reading tracks, so an include of
 * tracks and a related read returning them need it. It counts how often it is asked, so a
 * test can tell a cached table from a rebuilt one.
 */
final class TrackReadScopes implements OperationScopeResolverInterface, ScopeCatalogInterface
{
    public int $calls = 0;

    private readonly CatalogScopes $catalog;

    public function __construct()
    {
        $this->catalog = new CatalogScopes();
    }

    public function requiredScopes(?TypeMetadataInterface $type, OperationKey $operation): array
    {
        ++$this->calls;

        if ($operation->type === 'tracks' && \in_array($operation->kind, [OperationKind::FetchOne, OperationKind::FetchCollection], true)) {
            return ['tracks:read'];
        }

        return $this->catalog->requiredScopes($type, $operation);
    }

    public function all(): array
    {
        return $this->catalog->all() + ['tracks:read' => 'Read tracks.'];
    }
}
