<?php

declare(strict_types=1);

namespace Workbench\App\ResponseDecl;

/**
 * The domain object behind {@see WidgetResource} — the produced resource an async widget
 * create eventually yields (the `303` completion target a `widget-jobs` job redirects to).
 */
final class Widget
{
    public function __construct(
        public string $id = '',
        public string $name = '',
    ) {}
}
