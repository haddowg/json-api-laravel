<?php

declare(strict_types=1);

use haddowg\JsonApiLaravel\Testing\JsonApiAssertions;

require_once __DIR__ . '/vendor/autoload.php';

// Register the JSON:API TestResponse macros so larastan's macro detection — which
// reflects the live Illuminate\Support\Traits\Macroable::$macros at analysis time —
// resolves assertJsonApiDocument()/assertFetchedMany()/… (and their signatures) at
// every call site. Idempotent; runs only under PHPStan, never in the test suite.
JsonApiAssertions::registerMacros();
