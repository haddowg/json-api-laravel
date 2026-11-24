<?php

declare(strict_types=1);

namespace haddowg\JsonApiLaravel\DataProvider\Eloquent;

use haddowg\JsonApi\Resource\Field\ForeignKey;
use haddowg\JsonApi\Resource\Field\IdEncoderInterface;
use haddowg\JsonApi\Resource\Field\StoredIdForms;
use haddowg\JsonApiLaravel\DataProvider\ForeignKeyParents;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\MorphTo;

/**
 * The related rows a page of parents holds through a foreign key, as one query scope and the
 * match back to each parent. Each parent's key binds as the related model stores the foreign
 * key, through the column's value-object cast when it declares one, and a row matches the
 * parent whose stored key it holds.
 *
 * A foreign key naming a `MorphTo` identified by columns also scopes its type column to the
 * values naming the parents' type, and its id column, which may hold a parent's storage key or
 * its wire id, matches either. One naming an Eloquent `morphTo` scopes its type column to the
 * morph class of the parents' model.
 *
 * @internal
 */
final readonly class ForeignKeyScope
{
    /**
     * @param string                $column     the qualified id column
     * @param string                $attribute  the attribute a row holds the id under
     * @param string|null           $typeColumn the qualified type column of a polymorphic foreign key
     * @param list<string>          $types      the stored type values naming the parents' type
     * @param list<int|string>      $keys       every stored form a parent's key takes in the column
     * @param array<array-key, string> $parentOf   each stored form, compared as a string, to its parent's wire id
     */
    private function __construct(
        public string $column,
        public string $attribute,
        public ?string $typeColumn,
        public array $types,
        public array $keys,
        public array $parentOf,
    ) {}

    /**
     * @param string|null $parentMorphClass the morph class of the model serving the parents' type, which a native `morphTo` stores
     *
     * @throws \LogicException when a to-one foreign key names no `BelongsTo` of the related model
     */
    public static function of(Model $related, string $relatedType, ForeignKey $foreignKey, ForeignKeyParents $parents, ?IdEncoderInterface $parentEncoder, ?string $parentMorphClass = null): self
    {
        $morph = $foreignKey->morphColumns();
        $typeColumn = null;
        $types = [];
        if ($morph !== null) {
            $attribute = $morph->id;
            $typeColumn = $related->qualifyColumn($morph->type);
            $types = $morph->storedValues($parents->type);
        } elseif ($foreignKey->isRelation()) {
            $belongsTo = EloquentDataProvider::belongsToOf($related, $foreignKey->column) ?? throw new \LogicException(\sprintf(
                'The foreign key "%s" of type "%s" is a to-one relation, but %s has no BelongsTo method "%s" to read it through.',
                $foreignKey->name,
                $relatedType,
                $related::class,
                $foreignKey->column,
            ));
            $attribute = $belongsTo->getForeignKeyName();
            if ($belongsTo instanceof MorphTo) {
                $typeColumn = $related->qualifyColumn($belongsTo->getMorphType());
                $types = $parentMorphClass !== null ? [$parentMorphClass] : [];
            }
        } else {
            $attribute = $foreignKey->column;
        }

        $keys = [];
        $parentOf = [];
        foreach ($parents->keys as $wireId => $key) {
            $forms = $morph !== null ? StoredIdForms::forms($parentEncoder, [$key]) : [$key];
            foreach ($forms as $form) {
                $stored = StoredKey::of($related, $attribute, $form);
                $compared = StoredKey::comparable($stored);
                if ($compared !== null && (\is_int($stored) || \is_string($stored)) && !isset($parentOf[$compared])) {
                    $keys[] = $stored;
                    $parentOf[$compared] = (string) $wireId;
                }
            }
        }

        return new self($related->qualifyColumn($attribute), $attribute, $typeColumn, $types, $keys, $parentOf);
    }


    /**
     * Narrows `$builder` to the rows of the parents this scope holds.
     *
     * @template TModel of Model
     *
     * @param Builder<TModel> $builder
     *
     * @return Builder<TModel>
     */
    public function apply(Builder $builder): Builder
    {
        if ($this->keys === [] || ($this->typeColumn !== null && $this->types === [])) {
            return $builder->whereRaw('1 = 0');
        }
        if ($this->typeColumn !== null) {
            $builder->whereIn($this->typeColumn, $this->types);
        }

        return $builder->whereIn($this->column, $this->keys);
    }

    /**
     * The wire id of the parent whose key `$row` holds, or `null` when it holds none of theirs.
     */
    public function parentOf(Model $row): ?string
    {
        return $this->parentOfStored(StoredKey::on($row, $this->attribute));
    }

    /**
     * The wire id of the parent whose key the stored value `$stored` is, or `null`.
     */
    public function parentOfStored(mixed $stored): ?string
    {
        $compared = StoredKey::comparable($stored);

        return $compared === null ? null : ($this->parentOf[$compared] ?? null);
    }

    /**
     * The same scope narrowed to the parent with `$wireId`.
     */
    public function only(string $wireId): self
    {
        $keys = [];
        $parentOf = [];
        foreach ($this->keys as $key) {
            $compared = (string) $key;
            if (($this->parentOf[$compared] ?? null) === $wireId) {
                $keys[] = $key;
                $parentOf[$compared] = $wireId;
            }
        }

        return new self($this->column, $this->attribute, $this->typeColumn, $this->types, $keys, $parentOf);
    }
}
