<?php

declare(strict_types=1);

namespace haddowg\JsonApiLaravel\Tests\Fixtures\ThroughRelations;

enum ThrTemplateGroup: string
{
    case Onboarding = 'onboarding';
    case Feedback = 'feedback';
    case Retired = 'retired';
}
