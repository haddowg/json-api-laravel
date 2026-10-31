<?php

declare(strict_types=1);

namespace haddowg\JsonApiLaravel\Tests\Fixtures\RelationshipEntityConstraints;

use haddowg\JsonApi\Resource\Constraint\Context;
use haddowg\JsonApiLaravel\Validation\EntityConstraintInterface;

/**
 * A custom entity-level constraint: `$check` returns the violation message for an
 * entity, or null when it passes. {@see EntityCheckTranslator} turns it into a rule.
 */
final readonly class EntityCheck implements EntityConstraintInterface
{
    /**
     * @param \Closure(object): ?string $check
     */
    public function __construct(public \Closure $check, public Context $context = new Context()) {}

    public function context(): Context
    {
        return $this->context;
    }
}
