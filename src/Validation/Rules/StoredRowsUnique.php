<?php

declare(strict_types=1);

namespace haddowg\JsonApiLaravel\Validation\Rules;

use haddowg\JsonApi\Operation\QueryParameters;
use haddowg\JsonApi\Resource\AbstractResource;
use haddowg\JsonApi\Resource\Field\Accessor;
use haddowg\JsonApiLaravel\DataProvider\CollectionCriteria;
use haddowg\JsonApiLaravel\DataProvider\DataProviderRegistry;
use haddowg\JsonApiLaravel\Validation\Constraint\UniqueEntity;
use Illuminate\Contracts\Validation\DataAwareRule;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Database\Eloquent\Model;

/**
 * A {@see UniqueEntity} checked against the stored rows: an Eloquent query with the model's
 * global scopes, narrowed by the type's scoping provider when the constraint is scoped, or a
 * read of the type's provider when there is no model. The row being updated never counts.
 *
 * @internal
 */
final class StoredRowsUnique implements ValidationRule, DataAwareRule
{
    /**
     * Runs even when the attribute it reports on is absent from the body.
     */
    public bool $implicit = true;

    /**
     * @var array<string, mixed>
     */
    private array $data = [];

    /**
     * @param array<string, string>    $columns the column each of the constraint's fields is stored in, keyed by field
     * @param class-string<Model>|null $model   the model whose rows are compared, or null to read the type's provider
     */
    public function __construct(
        private readonly UniqueEntity $constraint,
        private readonly AbstractResource $resource,
        private readonly array $columns,
        private readonly ?string $model,
        private readonly ?object $current,
        private readonly ?DataProviderRegistry $providers,
    ) {}

    /**
     * @param array<string, mixed> $data
     */
    public function setData(array $data): static
    {
        $this->data = $data;

        return $this;
    }

    public function validate(string $attribute, mixed $value, \Closure $fail): void
    {
        $values = [];
        foreach ($this->columns as $field => $column) {
            $values[$column] = $this->data[$field] ?? null;
        }

        if ($this->constraint->ignoreNull && \in_array(null, $values, true)) {
            return;
        }

        if (!$this->taken($values)) {
            return;
        }

        if ($this->constraint->message !== null) {
            $fail($this->constraint->message);

            return;
        }

        $fail('validation.unique')->translate();
    }

    /**
     * @param array<string, mixed> $values
     */
    private function taken(array $values): bool
    {
        $type = $this->resource::$type;
        $scoping = $this->constraint->scoped ? $this->providers?->scopingProviderFor($type) : null;

        if ($this->model === null) {
            return $this->takenInProvider($type, $values);
        }

        $model = new ($this->model)();
        $query = $model->newQuery();
        $using = $this->constraint->using;
        if (\is_string($using)) {
            $query->{$using}();
        } elseif ($using !== null) {
            $using($query);
        }
        if (!$this->constraint->scoped) {
            $query->withoutGlobalScopes();
        }

        foreach ($values as $column => $value) {
            $value === null ? $query->whereNull($model->qualifyColumn($column)) : $query->where($model->qualifyColumn($column), $value);
        }

        if ($this->current instanceof Model && $this->current instanceof $model && $this->current->exists) {
            $query->whereKeyNot($this->current->getKey());
        }

        // The type's own rows count only when its scoping provider returns them.
        if ($scoping !== null && $this->constraint->model === null) {
            foreach ($query->get() as $row) {
                if ($scoping->fetchOne($type, $this->resource->getId($row)) !== null) {
                    return true;
                }
            }

            return false;
        }

        return $query->exists();
    }

    /**
     * @param array<string, mixed> $values
     */
    private function takenInProvider(string $type, array $values): bool
    {
        if ($this->providers === null || !$this->providers->supportsType($type)) {
            return false;
        }

        $currentId = $this->current !== null ? $this->resource->getId($this->current) : null;
        $rows = $this->providers->forType($type)->fetchCollection($type, new CollectionCriteria(new QueryParameters([], [], [], [], [])))->items;
        foreach ($rows as $row) {
            if (!\is_object($row) || ($currentId !== null && $currentId !== '' && $this->resource->getId($row) === $currentId)) {
                continue;
            }
            if ($this->matches($row, $values)) {
                return true;
            }
        }

        return false;
    }

    /**
     * @param array<string, mixed> $values
     */
    private function matches(object $row, array $values): bool
    {
        foreach ($values as $column => $value) {
            $stored = Accessor::get($row, $column);
            if ($value === null ? $stored !== null : !(\is_scalar($stored) && (string) $stored === (\is_scalar($value) ? (string) $value : null))) {
                return false;
            }
        }

        return true;
    }
}
