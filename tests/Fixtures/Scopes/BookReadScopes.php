<?php

declare(strict_types=1);

namespace haddowg\JsonApiLaravel\Tests\Fixtures\Scopes;

use haddowg\JsonApi\OpenApi\Metadata\OperationKey;
use haddowg\JsonApi\OpenApi\Metadata\OperationKind;
use haddowg\JsonApi\OpenApi\Metadata\TypeMetadataInterface;
use haddowg\JsonApi\Scope\OperationScopeResolverInterface;

/**
 * Reading books needs `books:read` and creating or changing them `books:write`; deleting
 * one is public, so it needs none. With `$scopeNotes` set
 * it also gives the public notes reads a scope, which the table build refuses. With
 * `$fail` set it throws. It counts how often it is asked.
 */
final class BookReadScopes implements OperationScopeResolverInterface
{
    public int $calls = 0;

    public bool $scopeNotes = false;

    public bool $fail = false;

    public function requiredScopes(?TypeMetadataInterface $type, OperationKey $operation): array
    {
        ++$this->calls;
        if ($this->fail) {
            throw new \RuntimeException('The scope service is down.');
        }

        $reads = \in_array($operation->kind, [OperationKind::FetchOne, OperationKind::FetchCollection], true);

        return match (true) {
            $operation->type === 'books' && $operation->kind === OperationKind::Delete => [],
            $operation->type === 'books' => [$reads ? 'books:read' : 'books:write'],
            $operation->type === 'notes' && $this->scopeNotes => ['notes:read'],
            default => [],
        };
    }
}
