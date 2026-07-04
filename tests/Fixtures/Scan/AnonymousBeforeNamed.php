<?php

declare(strict_types=1);

namespace haddowg\JsonApiLaravel\Tests\Fixtures\Scan;

use haddowg\JsonApi\Resource\AbstractResource;
use haddowg\JsonApi\Resource\Field\Id;
use haddowg\JsonApiLaravel\Attribute\AsJsonApiResource;

/**
 * A discovery-scan fixture whose FIRST `class` token in source order belongs to an
 * anonymous class (`new class extends AbstractResource`), declared before the real
 * named resource below. It reproduces the token-parsing edge case in
 * {@see \haddowg\JsonApiLaravel\Discovery\DiscoveryScanner::classInFile()}: without the
 * `T_NEW` guard the scanner reads the anonymous class's PARENT short name and returns
 * early, so the named declaration is never discovered. The closure is never invoked, so
 * the anonymous class is never instantiated when this file is autoloaded.
 *
 * @internal
 */
$anonymousBeforeNamed = static fn(): AbstractResource => new class extends AbstractResource {
    public static string $type = 'anonymous';

    public function fields(): array
    {
        return [Id::make()];
    }
};

/**
 * The real named resource the scanner must still discover past the anonymous class.
 *
 * @internal
 */
#[AsJsonApiResource(readOnly: true)]
final class AnonymousBeforeNamed extends AbstractResource
{
    public static string $type = 'scan-named';

    public function fields(): array
    {
        return [Id::make()];
    }
}
