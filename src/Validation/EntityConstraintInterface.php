<?php

declare(strict_types=1);

namespace haddowg\JsonApiLaravel\Validation;

use haddowg\JsonApi\Resource\Constraint\ConstraintInterface;

/**
 * Marks a constraint validated against the **hydrated entity** (post-hydration,
 * pre-commit) rather than the request document — for rules that need the persisted
 * object or a store the request cannot see.
 *
 * The bridge skips these in the document-first {@see ResourceValidator::validate()}
 * pass and runs them in {@see ResourceValidator::validateEntity()}, which the write
 * handler calls once the hydrator has built the entity. A constraint implementing this
 * interface translates (via the {@see ConstraintTranslator}, including any registered
 * {@see ConstraintTranslatorInterface}) to `illuminate/validation` rules validated
 * against the entity's attribute — so an application's own entity-level rule hooks into
 * the same seam by implementing this interface and registering a translator for it.
 *
 * The built-in {@see Constraint\UniqueEntity} is the one exception: PLAN decision 6
 * folds uniqueness into the document pass as a pre-hydration `Rule::unique`, so the
 * {@see ResourceValidator} intercepts it there and this post-hydration seam is retained
 * purely for genuinely-post-hydration custom checks.
 */
interface EntityConstraintInterface extends ConstraintInterface {}
