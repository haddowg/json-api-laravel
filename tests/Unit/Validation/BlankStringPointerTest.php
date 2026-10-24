<?php

declare(strict_types=1);

namespace haddowg\JsonApiLaravel\Tests\Unit\Validation;

use haddowg\JsonApi\Request\JsonApiRequest;
use haddowg\JsonApi\Resource\AbstractResource;
use haddowg\JsonApi\Resource\Constraint\In;
use haddowg\JsonApi\Resource\Constraint\MinLength;
use haddowg\JsonApi\Resource\Constraint\Pattern;
use haddowg\JsonApi\Resource\Field\ArrayList;
use haddowg\JsonApi\Resource\Field\Id;
use haddowg\JsonApi\Resource\Field\Map;
use haddowg\JsonApi\Resource\Field\Obj;
use haddowg\JsonApi\Resource\Field\OneOf;
use haddowg\JsonApi\Resource\Field\Str;
use haddowg\JsonApi\Resource\Field\StrBuilder;
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
 * An empty string under a `minLength(1)`, and a whitespace-only string under a pattern it
 * doesn't match, is a `422` at the pointer of the value that carries it, wherever the
 * constraint is declared: on the attribute, on an `Each` item, inside `Sequentially`,
 * `AtLeastOneOf` or `when()`, or on an `Obj`, `Map` or `OneOf` child. A whitespace-only
 * string is three characters long, so it satisfies `minLength(1)`, as in the bundle.
 *
 * @internal
 */
#[CoversClass(ResourceValidator::class)]
final class BlankStringPointerTest extends Orchestra
{
    /**
     * @return iterable<string, array{array<string, mixed>, list<string>}>
     */
    public static function documents(): iterable
    {
        yield 'attribute empty' => [['name' => ''], ['/data/attributes/name']];
        yield 'attribute whitespace' => [['name' => '   '], []];
        yield 'each item empty' => [['tags' => ['ok', '']], ['/data/attributes/tags/1']];
        yield 'each item whitespace' => [['tags' => ['   ']], []];
        yield 'each item pattern whitespace' => [['codes' => ['abc', '   ']], ['/data/attributes/codes/1']];
        yield 'each item pattern empty' => [['codes' => ['']], []];
        yield 'sequentially empty' => [['sequenced' => ''], ['/data/attributes/sequenced']];
        yield 'atLeastOneOf empty' => [['either' => ''], ['/data/attributes/either']];
        yield 'atLeastOneOf whitespace' => [['either' => '   '], []];
        yield 'when empty' => [['conditional' => ''], ['/data/attributes/conditional']];
        yield 'when whitespace' => [['conditional' => '   '], []];
        yield 'pattern whitespace' => [['code' => '   '], ['/data/attributes/code']];
        yield 'pattern empty' => [['code' => ''], []];
        yield 'obj child empty' => [['profile' => ['nick' => '']], ['/data/attributes/profile/nick']];
        yield 'map child empty' => [['address' => ['street' => '']], ['/data/attributes/address/street']];
        yield 'map child whitespace' => [['address' => ['street' => '   ']], []];
        yield 'oneOf child empty' => [['block' => ['kind' => 'label', 'text' => '']], ['/data/attributes/block/text']];
        yield 'oneOf child whitespace' => [['block' => ['kind' => 'label', 'text' => '   ']], []];
    }

    /**
     * @param array<string, mixed> $attributes
     * @param list<string>         $pointers
     */
    #[Test]
    #[DataProvider('documents')]
    public function aBlankStringIsRejectedAtThePointerOfTheValueCarryingIt(array $attributes, array $pointers): void
    {
        self::assertSame($pointers, $this->pointers($attributes));
    }

    /**
     * @param array<string, mixed> $attributes
     *
     * @return list<string>
     */
    private function pointers(array $attributes): array
    {
        $app = $this->app;
        \assert($app !== null);
        /** @var ValidationFactory $factory */
        $factory = $app->make(ValidationFactory::class);
        $validator = new ResourceValidator($factory, new ConstraintTranslator(), new JsonPointerBuilder());

        $psr = new Psr17Factory();
        $request = new JsonApiRequest(
            $psr->createServerRequest('POST', 'http://localhost/api/blanks')
                ->withHeader('Content-Type', 'application/vnd.api+json')
                ->withBody($psr->createStream(\json_encode(
                    ['data' => ['type' => 'blanks', 'attributes' => $attributes]],
                    \JSON_THROW_ON_ERROR,
                ))),
        );

        try {
            $validator->validate(new BlankStringResource(), $request, true);
        } catch (ValidationFailed $failed) {
            $found = [];
            foreach ($failed->getErrors() as $error) {
                $found[] = (string) $error->source?->pointer;
            }

            return $found;
        }

        return [];
    }
}

/**
 * @internal
 */
final class BlankStringResource extends AbstractResource
{
    public static string $type = 'blanks';

    public function fields(): array
    {
        return [
            Id::make(),
            Str::make('name')->minLength(1),
            Str::make('code')->pattern('^[a-z]+$'),
            ArrayList::make('tags')->each(new MinLength(1)),
            ArrayList::make('codes')->each(new Pattern('^[a-z]+$')),
            Str::make('sequenced')->sequentially(new MinLength(1)),
            Str::make('either')->atLeastOneOf(new MinLength(1), new In(['x'])),
            Str::make('conditional')->when(
                static fn(): bool => true,
                static function (StrBuilder $field): void {
                    $field->minLength(1);
                },
            ),
            Obj::make('profile')->fields(Str::make('nick')->minLength(1)),
            Map::make('address')->fields(Str::make('street')->minLength(1)),
            OneOf::make('block')->discriminator('kind')
                ->variant('label', Str::make('text')->minLength(1)),
        ];
    }
}
