<?php

declare(strict_types=1);

namespace Workbench\App\MusicCatalog\Security;

use haddowg\JsonApi\OpenApi\Metadata\OperationKey;
use haddowg\JsonApi\OpenApi\Metadata\OperationKind;
use haddowg\JsonApi\OpenApi\Metadata\TypeMetadataInterface;
use haddowg\JsonApi\Scope\OperationScopeResolverInterface;
use haddowg\JsonApi\Scope\ScopeCatalogInterface;

/**
 * The music catalogue's OAuth scopes, the same as the Symfony example's: writes to albums
 * need `catalog:write` and writes to playlists `library:write`, and every read is free. An
 * action needs its type's scope unless it is a `GET` or declares itself read-only. The
 * Atomic Operations endpoint's `batch` scope comes from config, and two declarations
 * override the resolver: deleting a playlist also needs `library:admin`, and the album
 * artwork upload also needs `catalog:media`.
 */
final class CatalogScopes implements OperationScopeResolverInterface, ScopeCatalogInterface
{
    private const array TYPE_SCOPES = [
        'albums' => 'catalog:write',
        'playlists' => 'library:write',
    ];

    private const array WRITES = [
        OperationKind::Create,
        OperationKind::Update,
        OperationKind::Delete,
        OperationKind::UpdateRelationship,
        OperationKind::AddRelationship,
        OperationKind::RemoveRelationship,
    ];

    public function requiredScopes(?TypeMetadataInterface $type, OperationKey $operation): array
    {
        $scope = self::TYPE_SCOPES[$operation->type ?? ''] ?? null;
        if ($scope === null) {
            return [];
        }

        if ($operation->kind === OperationKind::Action) {
            return $operation->method === 'GET' || $operation->safety?->readOnly === true ? [] : [$scope];
        }

        return \in_array($operation->kind, self::WRITES, true) ? [$scope] : [];
    }

    public function all(): array
    {
        return [
            'catalog:write' => 'Create, change and delete albums and their relationships.',
            'catalog:media' => 'Upload album artwork.',
            'library:write' => 'Create and change playlists and their tracks.',
            'library:admin' => 'Delete playlists.',
            'batch' => 'Send Atomic Operations batches.',
        ];
    }
}
