<?php

declare(strict_types=1);

namespace haddowg\JsonApiLaravel\Atomic;

use haddowg\JsonApi\Exception\JsonApiExceptionInterface;
use haddowg\JsonApi\Schema\Error\Error;

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
final class AtomicOperationNotExposed extends \RuntimeException implements JsonApiExceptionInterface
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

    public function getErrors(): array
    {
        return [
            new Error(
                status: '403',
                code: 'ATOMIC_OPERATION_NOT_EXPOSED',
                title: 'Atomic operation not exposed',
                detail: $this->getMessage(),
            ),
        ];
    }

    public function getStatusCode(): int
    {
        return 403;
    }
}
