<?php

declare(strict_types=1);

namespace haddowg\JsonApiLaravel\Tests\Unit\Validation;

use haddowg\JsonApi\Hydrator\Relationship\ToManyRelationship;
use haddowg\JsonApi\OpenApi\EnumComponentCollector;
use haddowg\JsonApi\OpenApi\Schema;
use haddowg\JsonApi\OpenApi\SchemaProjector;
use haddowg\JsonApi\Request\JsonApiRequest;
use haddowg\JsonApi\Resource\AbstractResource;
use haddowg\JsonApi\Resource\Constraint\Required;
use haddowg\JsonApi\Resource\Field\AbstractFieldValue;
use haddowg\JsonApi\Resource\Field\ArrayList;
use haddowg\JsonApi\Resource\Field\BelongsToMany;
use haddowg\JsonApi\Resource\Field\Boolean;
use haddowg\JsonApi\Resource\Field\FieldState;
use haddowg\JsonApi\Resource\Field\Id;
use haddowg\JsonApi\Resource\Field\Integer;
use haddowg\JsonApi\Resource\Field\OneOf;
use haddowg\JsonApi\Resource\Field\ProvidesFieldSchema;
use haddowg\JsonApi\Resource\Field\RelationInterface;
use haddowg\JsonApi\Resource\Field\Str;
use haddowg\JsonApi\Resource\Field\Union;
use haddowg\JsonApi\Schema\ResourceIdentifier;
use haddowg\JsonApiLaravel\Validation\ConstraintTranslator;
use haddowg\JsonApiLaravel\Validation\JsonPointerBuilder;
use haddowg\JsonApiLaravel\Validation\ResourceValidator;
use haddowg\JsonApiLaravel\Validation\ValidationFailed;
use Illuminate\Contracts\Validation\Factory as ValidationFactory;
use Nyholm\Psr7\Factory\Psr17Factory;
use Orchestra\Testbench\TestCase as Orchestra;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;

/**
 * A required field whose empty value is still a value, a number, a boolean, a `OneOf` or
 * a `Union`, only has to be present and not `null`, while any other field, an
 * application's own included, keeps Laravel's `required`. A required union whose member
 * has its own minimum reports an empty value once, by that minimum. An empty object for a required
 * `OneOf` therefore selects no variant and gets that error, as in the bundle. A union in a
 * linkage member's pivot `meta` meets its selected member's rules at the pivot pointer.
 *
 * @internal
 */
#[CoversClass(ResourceValidator::class)]
final class UnionValidationTest extends Orchestra
{
    /**
     * @return iterable<string, array{array<string, mixed>, array<string, string>}>
     */
    public static function requiredDocuments(): iterable
    {
        $valid = ['count' => 1, 'flag' => true, 'block' => ['kind' => 'note', 'text' => 'Hi'], 'amount' => '1.00', 'code' => 'abc'];

        yield 'zero and false' => [[...$valid, 'count' => 0, 'flag' => false], []];
        yield 'absent' => [[], [
            '/data/attributes/amount' => 'The amount field is required.',
            '/data/attributes/block' => 'The block field is required.',
            '/data/attributes/code' => 'The code field is required.',
            '/data/attributes/count' => 'The count field is required.',
            '/data/attributes/flag' => 'The flag field is required.',
        ]];
        yield 'null' => [['count' => null, 'flag' => null, 'block' => null, 'amount' => null, 'code' => null], [
            '/data/attributes/amount' => 'The amount field is required.',
            '/data/attributes/block' => 'The block field is required.',
            '/data/attributes/code' => 'The code field is required.',
            '/data/attributes/count' => 'The count field is required.',
            '/data/attributes/flag' => 'The flag field is required.',
        ]];
        yield 'empty object for a required OneOf' => [[...$valid, 'block' => []], [
            '/data/attributes/block/kind' => 'This value is not one of the allowed types.',
        ]];
        yield 'empty string for a required custom field' => [[...$valid, 'amount' => ''], [
            '/data/attributes/amount' => 'The amount field is required.',
        ]];
        yield 'empty string for a required union whose string member has a minimum' => [[...$valid, 'code' => ''], [
            '/data/attributes/code' => 'The code field must be at least 3 characters.',
        ]];
        yield 'empty list for a required union whose list member has a minimum' => [[...$valid, 'code' => []], [
            '/data/attributes/code' => 'The code field must have at least 2 items.',
        ]];
    }

    /**
     * @param array<string, mixed>  $attributes
     * @param array<string, string> $details
     */
    #[Test]
    #[DataProvider('requiredDocuments')]
    public function aRequiredValueThatMayBeEmptyOnlyHasToBePresentAndNotNull(array $attributes, array $details): void
    {
        self::assertSame($details, $this->detailsOf(fn(ResourceValidator $validator) => $validator->validate(
            new RequiredValuesResource(),
            $this->request('requireds', ['data' => ['type' => 'requireds', 'attributes' => $attributes]]),
            true,
        )));
    }

    /**
     * @return iterable<string, array{mixed, array<string, string>}>
     */
    public static function pivotValues(): iterable
    {
        yield 'string member' => ['ok', []];
        yield 'string member too long' => [\str_repeat('x', 11), ['/data/0/meta/pivot/tag' => 'The tag field must not be greater than 10 characters.']];
        yield 'list element too long' => [['ok', \str_repeat('x', 11)], ['/data/0/meta/pivot/tag/1' => 'The tag field must not be greater than 10 characters.']];
        yield 'integer member below its minimum' => [-1, ['/data/0/meta/pivot/tag' => 'The tag field must be at least 0.']];
        yield 'type mismatch' => [1.5, ['/data/0/meta/pivot/tag' => 'The tag field must be a string, an integer or a list.']];
        yield 'empty string for a required union' => ['', ['/data/0/meta/pivot/tag' => 'The tag field is required.']];
        yield 'empty list for a required union' => [[], ['/data/0/meta/pivot/tag' => 'The tag field is required.']];
        yield 'zero for a required union' => [0, []];
    }

    /**
     * @param array<string, string> $details
     */
    #[Test]
    #[DataProvider('pivotValues')]
    public function aPivotUnionMeetsItsMembersRulesAtThePivotPointer(mixed $tag, array $details): void
    {
        $relation = (new TaggedResource())->relation();
        $linkage = new ToManyRelationship([new ResourceIdentifier('tracks', '1', meta: ['pivot' => ['tag' => $tag]])]);

        self::assertSame($details, $this->detailsOf(fn(ResourceValidator $validator) => $validator->validateRelationshipLinkage(
            $relation,
            $linkage,
            request: $this->request('tagged/1/relationships/tracks', ['data' => [['type' => 'tracks', 'id' => '1', 'meta' => ['pivot' => ['tag' => $tag]]]]]),
        )));
    }

    /**
     * Each error's detail by its pointer, sorted by pointer.
     *
     * @param \Closure(ResourceValidator): void $validate
     *
     * @return array<string, string>
     */
    private function detailsOf(\Closure $validate): array
    {
        $app = $this->app;
        \assert($app !== null);
        /** @var ValidationFactory $factory */
        $factory = $app->make(ValidationFactory::class);

        try {
            $validate(new ResourceValidator($factory, new ConstraintTranslator(), new JsonPointerBuilder()));
        } catch (ValidationFailed $failed) {
            $details = [];
            foreach ($failed->getErrors() as $error) {
                $details[(string) $error->source?->pointer] = (string) $error->detail;
            }
            \ksort($details);

            return $details;
        }

        return [];
    }

    /**
     * @param array<string, mixed> $document
     */
    private function request(string $path, array $document): JsonApiRequest
    {
        $psr = new Psr17Factory();

        return new JsonApiRequest(
            $psr->createServerRequest('POST', 'http://localhost/api/' . $path)
                ->withHeader('Content-Type', 'application/vnd.api+json')
                ->withBody($psr->createStream(\json_encode($document, \JSON_THROW_ON_ERROR))),
        );
    }
}

/**
 * @internal
 */
final class RequiredValuesResource extends AbstractResource
{
    public static string $type = 'requireds';

    public function fields(): array
    {
        return [
            Id::make(),
            Integer::make('count')->required(),
            Boolean::make('flag')->required(),
            OneOf::make('block')->required()->discriminator('kind')
                ->variant('note', Str::make('text')),
            new MoneyField(new FieldState('amount', 'amount', constraints: [new Required()])),
            Union::make('code')->of(Str::make()->minLength(3), ArrayList::make()->minItems(2))->required(),
        ];
    }
}

/**
 * An application's own field type, which describes its own schema.
 *
 * @internal
 */
final readonly class MoneyField extends AbstractFieldValue implements ProvidesFieldSchema
{
    public function projectFieldSchema(SchemaProjector $projector, bool $creating, ?EnumComponentCollector $collector): Schema
    {
        return Schema::ofType('string')->withFormat('money');
    }
}

/**
 * @internal
 */
final class TaggedResource extends AbstractResource
{
    public static string $type = 'tagged';

    public function fields(): array
    {
        return [
            Id::make(),
            $this->relation(),
        ];
    }

    public function relation(): RelationInterface
    {
        return BelongsToMany::make('tracks', 'tracks')->fields(
            Union::make('tag')->of(
                Str::make()->maxLength(10),
                Integer::make()->min(0),
                ArrayList::make()->of(Union::of(Str::make()->maxLength(10))),
            )->required()->build(),
        )->build();
    }
}
