<?php

declare(strict_types=1);

namespace haddowg\JsonApiLaravel\Tests\Fixtures\RelationshipEntityConstraints;

/**
 * The in-memory twin of the models: a to-one holds the related object, a to-many a
 * list of them.
 */
final class Domain
{
    /**
     * @param list<Domain> $companies
     */
    public function __construct(
        public string $id = '',
        public string $name = '',
        public ?Domain $primaryCompany = null,
        public array $companies = [],
    ) {}
}
