<?php

declare(strict_types = 1);

namespace Tests\Unit\Concerns;

use Illuminate\Support\Facades\Config;
use Illuminate\Validation\ValidationException;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use SineMacula\ApiToolkit\Concerns\QuerySelectionValidator;
use SineMacula\ApiToolkit\Http\Resources\ApiResource;
use SineMacula\ApiToolkit\Http\Resources\ResourceDiscovery;
use SineMacula\ApiToolkit\Schema\Field;
use Tests\Concerns\LocalisesToolkitMessages;
use Tests\Fixtures\Models\Organization;
use Tests\Fixtures\Resources\AliasedScalarUserResource;
use Tests\Fixtures\Resources\OrganizationResource;
use Tests\Fixtures\Resources\PostResource;
use Tests\Fixtures\Resources\TagResource;
use Tests\Fixtures\Resources\UserResource;
use Tests\TestCase;

/**
 * Tests for the QuerySelectionValidator concern class.
 *
 * @author      Ben Carey <bdmc@sinemacula.co.uk>
 * @copyright   2026 Sine Macula Limited.
 *
 * @internal
 */
#[CoversClass(QuerySelectionValidator::class)]
final class QuerySelectionValidatorTest extends TestCase
{
    use LocalisesToolkitMessages;

    /** @var \SineMacula\ApiToolkit\Concerns\QuerySelectionValidator */
    private QuerySelectionValidator $validator;

    /**
     * Set up the validator over a catalogue of fixture resources.
     *
     * @return void
     */
    #[\Override]
    protected function setUp(): void
    {
        parent::setUp();

        Config::set(ResourceDiscovery::RESOURCES_KEY, [
            UserResource::class,
            AliasedScalarUserResource::class,
            PostResource::class,
            TagResource::class,
        ]);

        $this->validator = new QuerySelectionValidator;
    }

    /**
     * Provide selections every catalogued resource of the type accepts.
     *
     * @return iterable<string, array{array<string, mixed>}>
     */
    public static function acceptedSelectionProvider(): iterable
    {
        yield 'no selection' => [[]];
        yield 'declared fields' => [['fields' => ['users' => ['name', 'email']]]];
        yield 'the all token' => [['fields' => ['users' => [':all']]]];
        yield 'the type discriminator' => [['fields' => ['users' => ['_type']]]];
        yield 'the aggregates object where an aggregate is declared' => [['fields' => ['posts' => ['aggregates']]]];
        yield 'an aliased key' => [['fields' => ['aliased_users' => ['email']]]];
        yield 'empty names' => [['fields' => ['users' => ['', 'name', '']], 'aggregates' => ['users' => ['']]]];
        yield 'declared aggregates' => [['aggregates' => ['users' => ['posts_count', 'posts_sum_id', 'posts_avg_id']]]];
        yield 'several types' => [['fields' => ['users' => ['name'], 'posts' => ['title'], 'tags' => ['name']]]];
        yield 'other parameters' => [['filters' => ['nope' => 1], 'order' => ['nope' => 'asc']]];
    }

    /**
     * Test that a selection of declared names passes.
     *
     * @param  array<string, mixed>  $parameters
     * @return void
     */
    #[DataProvider('acceptedSelectionProvider')]
    public function testAcceptedSelectionPasses(array $parameters): void
    {
        $this->expectNotToPerformAssertions();

        $this->validator->validate($parameters);
    }

    /**
     * Provide selections the catalogue rejects, with the errors they raise.
     *
     * @return iterable<string, array{array<string, mixed>, array<string, array<int, string>>}>
     */
    public static function rejectedSelectionProvider(): iterable
    {
        yield 'an unknown type' => [
            ['fields' => ['user' => ['name']]],
            ['fields.user' => ['The "user" resource type is not known.']],
        ];

        yield 'an integer key' => [
            ['fields' => [0 => ['name']]],
            ['fields.0' => ['The "0" resource type is not known.']],
        ];

        yield 'an unknown aggregate type' => [
            ['aggregates' => ['user' => ['posts_count']]],
            ['aggregates.user' => ['The "user" resource type is not known.']],
        ];

        yield 'an unknown field' => [
            ['fields' => ['users' => ['name', 'emial']]],
            ['fields.users' => ['The "emial" field is not declared on the "users" resource type.']],
        ];

        yield 'the aggregates object where no aggregate is declared' => [
            ['fields' => ['tags' => ['aggregates']]],
            ['fields.tags' => ['The "aggregates" field is not declared on the "tags" resource type.']],
        ];

        yield 'an aggregate asked for as a field' => [
            ['fields' => ['users' => ['posts_count']]],
            ['fields.users' => ['The "posts_count" field is not declared on the "users" resource type.']],
        ];

        yield 'the attribute behind an aliased key' => [
            ['fields' => ['aliased_users' => ['email_address']]],
            ['fields.aliased_users' => ['The "email_address" field is not declared on the "aliased_users" resource type.']],
        ];

        yield 'the all token as an aggregate' => [
            ['aggregates' => ['users' => [':all']]],
            ['aggregates.users' => ['The ":all" aggregate is not declared on the "users" resource type.']],
        ];

        yield 'a field asked for as an aggregate' => [
            ['aggregates' => ['users' => ['name']]],
            ['aggregates.users' => ['The "name" aggregate is not declared on the "users" resource type.']],
        ];

        yield 'an unknown aggregate' => [
            ['aggregates' => ['users' => ['posts_count', 'post']]],
            ['aggregates.users' => ['The "post" aggregate is not declared on the "users" resource type.']],
        ];

        yield 'an unknown type after a known one' => [
            ['fields' => ['users' => ['name'], 'user' => ['name']]],
            ['fields.user' => ['The "user" resource type is not known.']],
        ];

        yield 'every unknown at once' => [
            [
                'fields'     => ['users' => ['emial', 'nmae'], 'user' => ['name']],
                'aggregates' => ['users' => ['post']],
            ],
            [
                'fields.users' => [
                    'The "emial" field is not declared on the "users" resource type.',
                    'The "nmae" field is not declared on the "users" resource type.',
                ],
                'fields.user'      => ['The "user" resource type is not known.'],
                'aggregates.users' => ['The "post" aggregate is not declared on the "users" resource type.'],
            ],
        ];
    }

    /**
     * Test that a selection naming an unknown type or name is rejected with
     * every unknown at once.
     *
     * @param  array<string, mixed>  $parameters
     * @param  array<string, array<int, string>>  $errors
     * @return void
     */
    #[DataProvider('rejectedSelectionProvider')]
    public function testUnknownSelectionIsRejected(array $parameters, array $errors): void
    {
        try {
            $this->validator->validate($parameters);
            self::fail('An unknown selection passed validation.');
        } catch (ValidationException $exception) {
            self::assertSame($errors, $exception->errors());
        }
    }

    /**
     * Test that a type shared by several resources accepts the names of each.
     *
     * @return void
     */
    public function testSharedTypeAcceptsTheNamesOfEveryResource(): void
    {
        $other = new class (null) extends ApiResource {
            /** @var string */
            public const string RESOURCE_TYPE = 'users';

            /**
             * Return the resource schema.
             *
             * @return array<string, array<string, mixed>>
             */
            #[\Override]
            public static function schema(): array
            {
                return Field::set(Field::scalar('nickname'));
            }
        };

        Config::set(ResourceDiscovery::RESOURCES_KEY, [UserResource::class, $other::class]);

        $this->expectNotToPerformAssertions();

        $this->validator->validate(['fields' => ['users' => ['email', 'nickname']]]);
    }

    /**
     * Test that a mapped resource is selectable without being discovered.
     *
     * @return void
     */
    public function testMappedResourceIsSelectable(): void
    {
        Config::set('api-toolkit.resources.resource_map', [Organization::class => OrganizationResource::class]);

        $this->expectNotToPerformAssertions();

        $this->validator->validate(['fields' => ['organizations' => ['slug']], 'aggregates' => ['organizations' => ['users_count']]]);
    }

    /**
     * Test that the rejection is worded in the current locale.
     *
     * @return void
     */
    public function testRejectionIsWordedInTheCurrentLocale(): void
    {
        $this->localiseToolkitMessages(['fields.undeclared' => 'Das Feld ":name" ist für ":type" nicht deklariert.']);

        try {
            $this->validator->validate(['fields' => ['users' => ['emial']]]);
            self::fail('An unknown field passed validation.');
        } catch (ValidationException $exception) {
            self::assertSame(['fields.users' => ['Das Feld "emial" ist für "users" nicht deklariert.']], $exception->errors());
        }
    }
}
