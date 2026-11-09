<?php

declare(strict_types=1);

namespace Workbench\App\Validation;

enum SubmissionKind: string
{
    case Draft = 'draft';
    case Final = 'final';
}
