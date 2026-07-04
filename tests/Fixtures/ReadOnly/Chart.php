<?php

declare(strict_types=1);

namespace haddowg\JsonApiLaravel\Tests\Fixtures\ReadOnly;

/**
 * A plain read-only domain object for the persister-less authorization fixtures: a chart
 * entry seeded into an in-memory read provider that has NO persister (a read-only type).
 * It backs both the deny-all {@see ChartResource} and the policy-less {@see LabelResource}.
 */
final class Chart
{
    public function __construct(
        public string $id = '',
        public string $title = '',
    ) {}
}
