<?php

declare(strict_types=1);

namespace haddowg\JsonApiLaravel\Atomic;

use haddowg\JsonApi\Exception\DescribedErrorInterface;
use haddowg\JsonApi\Exception\ErrorContextType;
use haddowg\JsonApi\Exception\ErrorDescriptor;
use haddowg\JsonApi\Exception\ErrorFeature;
use haddowg\JsonApi\Exception\ErrorScope;
use haddowg\JsonApi\OpenApi\Metadata\OperationKind;

/**
 * An atomic sub-operation would write a resource type in a way its HTTP surface forbids —
 * an `add`/`update`/`remove` (or a relationship mutation) against a type whose
 * {@see \haddowg\JsonApiLaravel\Discovery\ResourceDescriptor} does not expose the matching
 * CRUD operation (e.g. an `add` of a `readOnly` type, or any write of a type absent from
 * discovery).
 *
 * The direct HTTP surface enforces the per-type operation allow-list purely by routing —
 * an unexposed verb is unrouted (a `404`/`405`). Atomic sub-operations never touch the
 * router: the executor builds the CRUD operation VOs directly and dispatches in-process, so
 * without this gate a `readOnly` type would be writable via `POST /operations`. The
 * executor therefore re-applies the allow-list in its **pre-flight** scan — before opening
 * any transaction — and refuses the whole batch (`403 Forbidden`) when a sub-operation
 * targets an operation its type does not expose.
 */
final class AtomicOperationNotExposed extends \RuntimeException implements DescribedErrorInterface
{
    public function __construct(public readonly string $type, public readonly string $operation)
    {
        parent::__construct(\sprintf(
            'The atomic operation would perform "%s" on the JSON:API type "%s", but that type does not expose '
            . 'that operation on its HTTP surface (its operation allow-list omits it, or it declares no resource). '
            . 'Expose the operation on the type, or remove it from the batch.',
            $operation,
            $type,
        ));
    }

    public static function describe(): ErrorDescriptor
    {
        return new ErrorDescriptor(
            code: 'ATOMIC_OPERATION_NOT_EXPOSED',
            status: 403,
            title: 'Atomic operation not exposed',
            context: ['type' => ErrorContextType::Str, 'operation' => ErrorContextType::Str],
            feature: ErrorFeature::AtomicOperations,
            scope: ErrorScope::kinds(OperationKind::Atomic),
            description: 'An operation asks for a write its target type does not offer on this server: an `add` to a type that cannot be created, an `update` of one that cannot be updated, a `remove` of one that cannot be deleted, or a relationship operation on a type that cannot be updated. A batch makes only the writes the API exposes directly. The server applied none of the batch. Drop the operation and send the batch again.',
        );
    }

    public function getErrors(): array
    {
        return [
            self::describe()->toError(
                detail: $this->getMessage(),
                context: ['type' => $this->type, 'operation' => $this->operation],
            ),
        ];
    }

    public function getStatusCode(): int
    {
        return self::describe()->status;
    }
}
