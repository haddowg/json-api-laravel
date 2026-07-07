<?php

declare(strict_types=1);

namespace Workbench\App\ResponseDecl;

/**
 * The pollable job resource behind {@see WidgetJobResource}: a `GET` on it renders the
 * job status while `$status` is not `done`, and redirects (`303`) to the produced widget
 * once it is — the read-side completion of the async lifecycle.
 */
final class WidgetJob
{
    public function __construct(
        public string $id = '',
        public string $status = 'processing',
        public ?string $producedId = null,
    ) {}
}
