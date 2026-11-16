<?php

declare(strict_types = 1);

namespace Tests\Unit\Console;

use Illuminate\Database\Eloquent\Relations\Relation;
use Illuminate\Filesystem\Filesystem;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Config;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use SineMacula\ApiToolkit\Console\MakeResourceCommand;
use SineMacula\ApiToolkit\Facades\ApiQuery;
use SineMacula\ApiToolkit\Http\Resources\ApiResource;
use SineMacula\ApiToolkit\Http\Resources\ResourceDiscovery;
use SineMacula\ApiToolkit\Schema\Validation\SchemaValidator;
use Symfony\Component\Process\Process;
use Tests\Fixtures\Discovery\Primary\DiscoveredUserResource;
use Tests\Fixtures\Models\Generator\AbstractRecord;
use Tests\Fixtures\Models\Generator\ApiResource as ApiResourceModel;
use Tests\Fixtures\Models\Generator\Field as FieldModel;
use Tests\Fixtures\Models\Generator\KeylessRow;
use Tests\Fixtures\Models\Generator\Ticket;
use Tests\Fixtures\Models\Post;
use Tests\Fixtures\Models\Tag;
use Tests\Fixtures\Models\User;
use Tests\Fixtures\Resources\UserResource;
use Tests\TestCase;

/**
 * Tests for the MakeResourceCommand Artisan command.
 *
 * @author      Ben Carey <bdmc@sinemacula.co.uk>
 * @copyright   2026 Sine Macula Limited.
 *
 * @internal
 */
#[CoversClass(MakeResourceCommand::class)]
final class MakeResourceCommandTest extends TestCase
{
    /** @var string The command signature. */
    private const string COMMAND = 'api-toolkit:make:resource';

    /** @var string The namespace generated resources are placed in. */
    private const string NAMESPACE = 'App\Http\Resources\\';

    /** @var string The temporary root the application paths point into. */
    private string $root;

    /**
     * Set up the test environment.
     *
     * @return void
     */
    #[\Override]
    protected function setUp(): void
    {
        parent::setUp();

        assert($this->app !== null);

        $this->root = sys_get_temp_dir() . '/api-toolkit-make-resource-' . getmypid() . '-' . uniqid('', true);

        mkdir($this->root . '/app', 0o755, true);

        $this->app->getNamespace();
        $this->app->useAppPath($this->root . '/app');
        $this->useIsolatedBootstrapPath();
    }

    /**
     * Clean up the testing environment before the next test.
     *
     * @return void
     */
    #[\Override]
    protected function tearDown(): void
    {
        (new Filesystem)->deleteDirectory($this->root);

        parent::tearDown();
    }

    /**
     * Test that the command writes a resource bound to the model that declares
     * only the model key, with no warning or note under the default paths.
     *
     * @return void
     */
    public function testWritesAResourceExposingOnlyTheModelKey(): void
    {
        self::assertSame(0, Artisan::call(self::COMMAND, ['name' => 'PostResource', '--model' => Post::class]));

        $output = Artisan::output();

        self::assertStringContainsString('created successfully', $output);
        self::assertSame(1, substr_count($output, 'INFO'));
        self::assertSame(0, substr_count($output, 'WARN'));

        self::assertSame(<<<'PHP'
            <?php

            namespace App\Http\Resources;

            use SineMacula\ApiToolkit\Attributes\ForModel;
            use SineMacula\ApiToolkit\Http\Resources\ApiResource;
            use SineMacula\ApiToolkit\Schema\Field;
            use Tests\Fixtures\Models\Post;

            #[ForModel(Post::class)]
            class PostResource extends ApiResource
            {
                public const string RESOURCE_TYPE = 'post';

                protected static array $default = ['id'];

                public static function schema(): array
                {
                    return Field::set(
                        Field::scalar('id'),
                    );
                }
            }

            PHP, $this->generated('Http/Resources/PostResource.php'));
    }

    /**
     * Test that the generated resource loads, is discovered for its model,
     * passes schema validation and serialises only the key, by default and when
     * an undeclared field is requested.
     *
     * @return void
     */
    public function testGeneratedResourceIsDiscoveredValidatedAndServesItsKey(): void
    {
        $class = $this->generatedClass('DraftPostResource');

        $this->pendingArtisan(self::COMMAND, ['name' => 'DraftPostResource', '--model' => Post::class])
            ->assertExitCode(0)
            ->run();

        require $this->root . '/app/Http/Resources/DraftPostResource.php';

        assert($this->app !== null);

        /** @var \SineMacula\ApiToolkit\Http\Resources\ResourceDiscovery $discovery */
        $discovery = $this->app->make(ResourceDiscovery::class);

        $map = $discovery->scan();

        self::assertSame([Post::class => $class], $map);

        /** @var \SineMacula\ApiToolkit\Schema\Validation\SchemaValidator $validator */
        $validator = $this->app->make(SchemaValidator::class);
        $validator->validate($map);

        $post = Post::query()->create(['user_id' => 1, 'title' => 'Title', 'body' => 'Body']);

        self::assertSame(['_type' => 'post', 'id' => $post->getKey()], $this->serialise($class, $post, []));
        self::assertSame(['_type' => 'post', 'id' => $post->getKey()], $this->serialise($class, $post, ['fields' => ['post' => 'id,title']]));
    }

    /**
     * Test that the model is guessed from the class name under the application
     * models namespace when none or an empty one is given.
     *
     * @return void
     */
    public function testModelIsGuessedFromTheClassName(): void
    {
        $this->declareClass('Models/Order.php', "namespace App\\Models;\n\nclass Order extends \\Illuminate\\Database\\Eloquent\\Model {}");

        $this->pendingArtisan(self::COMMAND, ['name' => 'OrderResource'])->assertExitCode(0)->run();

        $contents = $this->generated('Http/Resources/OrderResource.php');

        self::assertStringContainsString("use App\\Models\\Order;\n", $contents);
        self::assertStringContainsString('#[ForModel(Order::class)]', $contents);
        self::assertStringContainsString('RESOURCE_TYPE = \'order\';', $contents);

        $this->pendingArtisan(self::COMMAND, ['name' => 'OrderResource', '--model' => '', '--force' => true])->assertExitCode(0)->run();

        self::assertSame($contents, $this->generated('Http/Resources/OrderResource.php'));
    }

    /**
     * Test that the application model wins over a framework facade alias of the
     * same name, whether guessed or given.
     *
     * @return void
     */
    public function testApplicationModelWinsOverAFacadeAlias(): void
    {
        self::assertTrue(class_exists('Event'));

        $this->declareClass('Models/Event.php', "namespace App\\Models;\n\nclass Event extends \\Illuminate\\Database\\Eloquent\\Model {}");

        $this->pendingArtisan(self::COMMAND, ['name' => 'EventResource'])->assertExitCode(0)->run();
        $this->pendingArtisan(self::COMMAND, ['name' => 'MeetingResource', '--model' => 'Event', '--type' => 'meeting'])->assertExitCode(0)->run();

        foreach (['EventResource', 'MeetingResource'] as $name) {

            $contents = $this->generated('Http/Resources/' . $name . '.php');

            self::assertStringContainsString("use App\\Models\\Event;\n", $contents);
            self::assertStringContainsString('#[ForModel(Event::class)]', $contents);
        }
    }

    /**
     * Test that a model outside the application namespace keeps its namespace,
     * written with its declared casing.
     *
     * @return void
     */
    public function testModelOutsideTheApplicationKeepsItsNamespace(): void
    {
        self::assertTrue(class_exists(User::class));

        $this->pendingArtisan(self::COMMAND, ['name' => 'MemberResource', '--model' => '\\' . strtolower(User::class)])
            ->assertExitCode(0)
            ->run();

        $contents = $this->generated('Http/Resources/MemberResource.php');

        self::assertStringContainsString("use Tests\\Fixtures\\Models\\User;\n", $contents);
        self::assertStringContainsString('#[ForModel(User::class)]', $contents);
        self::assertStringContainsString('RESOURCE_TYPE = \'user\';', $contents);
    }

    /**
     * Test that a model that does not exist fails without writing anything.
     *
     * @return void
     */
    public function testMissingModelFailsWithoutWriting(): void
    {
        $this->pendingArtisan(self::COMMAND, ['name' => 'OrderResource'])
            ->expectsOutputToContain('The model [App\Order] does not exist.')
            ->assertExitCode(1)
            ->run();

        self::assertDirectoryDoesNotExist($this->root . '/app/Http');
    }

    /**
     * Test that a class that is not an instantiable model fails without writing
     * anything.
     *
     * @return void
     */
    public function testNonModelClassFailsWithoutWriting(): void
    {
        foreach ([\stdClass::class, AbstractRecord::class] as $class) {
            $this->pendingArtisan(self::COMMAND, ['name' => 'OrderResource', '--model' => $class])
                ->expectsOutputToContain(sprintf('The class [%s] is not an instantiable Eloquent model.', $class))
                ->assertExitCode(1)
                ->run();
        }

        self::assertDirectoryDoesNotExist($this->root . '/app/Http');
    }

    /**
     * Test that a model keyed by a uuid column declares that key.
     *
     * @return void
     */
    public function testUuidKeyIsDeclaredAsTheKeyField(): void
    {
        $this->pendingArtisan(self::COMMAND, ['name' => 'TicketResource', '--model' => Ticket::class])
            ->assertExitCode(0)
            ->run();

        $contents = $this->generated('Http/Resources/TicketResource.php');

        self::assertStringContainsString('protected static array $default = [\'uuid\'];', $contents);
        self::assertStringContainsString('Field::scalar(\'uuid\'),', $contents);
    }

    /**
     * Test that a model without a primary key declares an empty schema.
     *
     * @return void
     */
    public function testKeylessModelDeclaresAnEmptySchema(): void
    {
        $this->pendingArtisan(self::COMMAND, ['name' => 'KeylessRowResource', '--model' => KeylessRow::class])
            ->assertExitCode(0)
            ->run();

        $contents = $this->generated('Http/Resources/KeylessRowResource.php');

        self::assertStringContainsString('RESOURCE_TYPE = \'keyless_row\';', $contents);
        self::assertStringContainsString('protected static array $default = [];', $contents);
        self::assertStringContainsString("return Field::set();\n", $contents);
        $this->assertLints($this->root . '/app/Http/Resources/KeylessRowResource.php');
    }

    /**
     * Test that the given type is lowercased.
     *
     * @return void
     */
    public function testGivenTypeIsLowercased(): void
    {
        $this->pendingArtisan(self::COMMAND, ['name' => 'PostResource', '--model' => Post::class, '--type' => 'Blog_Post'])
            ->assertExitCode(0)
            ->run();

        self::assertStringContainsString('RESOURCE_TYPE = \'blog_post\';', $this->generated('Http/Resources/PostResource.php'));
    }

    /**
     * Provide the resource types that cannot key a query field set.
     *
     * @return iterable<string, array{string, string}>
     */
    public static function invalidTypeProvider(): iterable
    {
        yield 'empty' => ['', '[]'];
        yield 'zero' => ['0', '[0]'];
        yield 'opening bracket' => ['po[st', '[po[st]'];
        yield 'closing bracket' => ['po]st', '[po]st]'];
    }

    /**
     * Test that a type that cannot key a query field set fails without writing
     * anything.
     *
     * @param  string  $type
     * @param  string  $shown
     * @return void
     */
    #[DataProvider('invalidTypeProvider')]
    public function testInvalidTypeFailsWithoutWriting(string $type, string $shown): void
    {
        $this->pendingArtisan(self::COMMAND, ['name' => 'PostResource', '--model' => Post::class, '--type' => $type])
            ->expectsOutputToContain(sprintf('The resource type %s must not be empty, "0" or contain square brackets.', $shown))
            ->assertExitCode(1)
            ->run();

        self::assertDirectoryDoesNotExist($this->root . '/app/Http');
    }

    /**
     * Test that a type holding a quote or backslash is escaped in the literal.
     *
     * @return void
     */
    public function testTypeIsEscapedInTheLiteral(): void
    {
        $this->pendingArtisan(self::COMMAND, ['name' => 'PostResource', '--model' => Post::class, '--type' => 'it\'s\x'])
            ->assertExitCode(0)
            ->run();

        self::assertStringContainsString('RESOURCE_TYPE = \'it\\\'s\\\x\';', $this->generated('Http/Resources/PostResource.php'));
        $this->assertLints($this->root . '/app/Http/Resources/PostResource.php');
    }

    /**
     * Test that a placeholder inside the type is written literally rather than
     * substituted.
     *
     * @return void
     */
    public function testPlaceholderInTheTypeIsWrittenLiterally(): void
    {
        $this->pendingArtisan(self::COMMAND, ['name' => 'PostResource', '--model' => Post::class, '--type' => '{{ fields }}{{ default }}'])
            ->assertExitCode(0)
            ->run();

        self::assertStringContainsString('RESOURCE_TYPE = \'{{ fields }}{{ default }}\';', $this->generated('Http/Resources/PostResource.php'));
        $this->assertLints($this->root . '/app/Http/Resources/PostResource.php');
    }

    /**
     * Test that a type an explicitly mapped resource returns fails.
     *
     * @return void
     */
    public function testTypeOfAnExplicitlyMappedResourceFails(): void
    {
        Config::set('api-toolkit.resources.resource_map', [User::class => UserResource::class]);

        $this->pendingArtisan(self::COMMAND, ['name' => 'PostResource', '--model' => Post::class, '--type' => 'users'])
            ->expectsOutputToContain(sprintf('The resource type [users] is already used by [%s].', UserResource::class))
            ->assertExitCode(1)
            ->run();

        self::assertDirectoryDoesNotExist($this->root . '/app/Http');
    }

    /**
     * Test that a type a discovered resource returns fails.
     *
     * @return void
     */
    public function testTypeOfADiscoveredResourceFails(): void
    {
        Config::set('api-toolkit.resources.paths', [$this->discoveryFixture('Primary')]);

        $this->pendingArtisan(self::COMMAND, ['name' => 'TagResource', '--model' => Tag::class, '--type' => 'discovered_users'])
            ->expectsOutputToContain(sprintf('The resource type [discovered_users] is already used by [%s].', DiscoveredUserResource::class))
            ->assertExitCode(1)
            ->run();
    }

    /**
     * Test that a forced rewrite of a discovered resource collides neither with
     * its own type nor with its own binding.
     *
     * @return void
     */
    public function testForcedRewriteDoesNotCollideWithItself(): void
    {
        $this->pendingArtisan(self::COMMAND, ['name' => 'ForcedPostResource', '--model' => Post::class])
            ->assertExitCode(0)
            ->run();

        require $this->root . '/app/Http/Resources/ForcedPostResource.php';

        $this->pendingArtisan(self::COMMAND, ['name' => 'ForcedPostResource', '--model' => Post::class, '--force' => true])
            ->expectsOutputToContain('created successfully')
            ->assertExitCode(0)
            ->run();
    }

    /**
     * Test that a model another discovered resource binds, with no explicit
     * entry, fails without writing anything.
     *
     * @return void
     */
    public function testModelBoundByDiscoveryFails(): void
    {
        Config::set('api-toolkit.resources.paths', [$this->discoveryFixture('Primary')]);

        $this->pendingArtisan(self::COMMAND, ['name' => 'MemberResource', '--model' => User::class, '--type' => 'member'])
            ->expectsOutputToContain(sprintf(
                'The model [%s] is already bound to [%s]. Declare the canonical resource in the resource_map.',
                User::class,
                DiscoveredUserResource::class,
            ))
            ->assertExitCode(1)
            ->run();

        self::assertDirectoryDoesNotExist($this->root . '/app/Http');
    }

    /**
     * Test that an explicit entry naming another resource writes the resource
     * with a note that the entry still decides the binding, and no morph alias
     * note for a type that will not be mapped.
     *
     * @return void
     */
    public function testExplicitEntryForAnotherResourceWritesWithANote(): void
    {
        Config::set('api-toolkit.resources.paths', [$this->discoveryFixture('Primary')]);
        Config::set('api-toolkit.resources.resource_map', [User::class => UserResource::class]);
        Config::set('api-toolkit.resources.enable_dynamic_morph_mapping', true);

        $this->pendingArtisan(self::COMMAND, ['name' => 'MemberResource', '--model' => User::class])
            ->expectsOutputToContain(sprintf(
                'The resource_map binds [%s] to [%s], so [%sMemberResource] is not used for it until that entry changes.',
                User::class,
                UserResource::class,
                self::NAMESPACE,
            ))
            ->doesntExpectOutputToContain('Polymorphic')
            ->assertExitCode(0)
            ->run();

        self::assertFileExists($this->root . '/app/Http/Resources/MemberResource.php');
    }

    /**
     * Test that an explicit entry naming the resource itself writes it under
     * force without a note.
     *
     * @return void
     */
    public function testExplicitEntryForTheResourceWritesWithoutANote(): void
    {
        Config::set('api-toolkit.resources.paths', [$this->discoveryFixture('Primary')]);
        Config::set('api-toolkit.resources.resource_map', [User::class => self::NAMESPACE . 'MemberResource']);

        $this->generate('Http/Resources/MemberResource.php', 'stale');

        $this->pendingArtisan(self::COMMAND, ['name' => 'MemberResource', '--model' => User::class, '--force' => true])
            ->doesntExpectOutputToContain('resource_map binds')
            ->assertExitCode(0)
            ->run();

        self::assertStringContainsString('class MemberResource extends ApiResource', $this->generated('Http/Resources/MemberResource.php'));
    }

    /**
     * Provide the models referenced by their qualified name.
     *
     * @return iterable<string, array{string, class-string}>
     */
    public static function qualifiedModelProvider(): iterable
    {
        yield 'field model' => ['FieldResource', FieldModel::class];
        yield 'api resource model' => ['ApiResourceResource', ApiResourceModel::class];
        yield 'class basename' => ['Post', Post::class];
    }

    /**
     * Test that a model whose short name clashes is referenced by its qualified
     * name and the file compiles.
     *
     * @param  string  $name
     * @param  class-string  $model
     * @return void
     */
    #[DataProvider('qualifiedModelProvider')]
    public function testClashingModelIsReferencedByItsQualifiedName(string $name, string $model): void
    {
        $this->pendingArtisan(self::COMMAND, ['name' => $name, '--model' => $model])->assertExitCode(0)->run();

        $path     = $this->root . '/app/Http/Resources/' . $name . '.php';
        $contents = $this->generated('Http/Resources/' . $name . '.php');

        self::assertStringContainsString('#[ForModel(\\' . $model . '::class)]', $contents);
        self::assertStringNotContainsString('use ' . $model . ';', $contents);
        $this->assertLints($path);
    }

    /**
     * Test that a model in the global namespace is referenced by its qualified
     * name.
     *
     * @return void
     */
    public function testGlobalModelIsReferencedByItsQualifiedName(): void
    {
        $this->declareClass('GeneratorGlobalWidget.php', 'class GeneratorGlobalWidget extends \Illuminate\Database\Eloquent\Model {}');

        $this->pendingArtisan(self::COMMAND, ['name' => 'WidgetResource', '--model' => 'GeneratorGlobalWidget'])
            ->assertExitCode(0)
            ->run();

        $contents = $this->generated('Http/Resources/WidgetResource.php');

        self::assertStringContainsString('#[ForModel(\GeneratorGlobalWidget::class)]', $contents);
        self::assertStringNotContainsString('use GeneratorGlobalWidget;', $contents);
    }

    /**
     * Provide the class names that clash with a name the resource imports.
     *
     * @return iterable<string, array{string}>
     */
    public static function importedNameProvider(): iterable
    {
        yield 'field' => ['Field'];
        yield 'lowercase field' => ['field'];
        yield 'api resource' => ['ApiResource'];
        yield 'for model' => ['ForModel'];
    }

    /**
     * Test that a class named like a class the resource imports fails without
     * writing anything.
     *
     * @param  string  $name
     * @return void
     */
    #[DataProvider('importedNameProvider')]
    public function testClassNamedLikeAnImportFails(string $name): void
    {
        $this->pendingArtisan(self::COMMAND, ['name' => $name, '--model' => Post::class])
            ->expectsOutputToContain(sprintf('The class name [%s] clashes with a class the resource imports.', $name))
            ->assertExitCode(1)
            ->run();

        self::assertDirectoryDoesNotExist($this->root . '/app/Http');
    }

    /**
     * Provide the discovery paths a resource falls outside of.
     *
     * @return iterable<string, array{mixed, string}>
     */
    public static function unreachedPathProvider(): iterable
    {
        yield 'outside the configured paths' => [['/nonexistent/resources'], 'is outside every resource discovery path'];
        yield 'no discovery paths' => [[], 'Resource discovery is disabled'];
        yield 'malformed discovery paths' => [false, 'Resource discovery is disabled'];
    }

    /**
     * Test that a resource discovery will not reach warns.
     *
     * @param  mixed  $paths
     * @param  string  $warning
     * @return void
     */
    #[DataProvider('unreachedPathProvider')]
    public function testUnreachedResourceWarns(mixed $paths, string $warning): void
    {
        Config::set('api-toolkit.resources.paths', $paths);

        $this->pendingArtisan(self::COMMAND, ['name' => 'PostResource', '--model' => Post::class])
            ->expectsOutputToContain($warning)
            ->assertExitCode(0)
            ->run();
    }

    /**
     * Test that a resource beneath a module root under the default paths does
     * not warn.
     *
     * @return void
     */
    public function testModuleRootUnderTheDefaultPathsDoesNotWarn(): void
    {
        $this->pendingArtisan(self::COMMAND, ['name' => 'App\Blog\Http\Resources\PostResource', '--model' => Post::class])
            ->doesntExpectOutputToContain('discovery')
            ->assertExitCode(0)
            ->run();

        self::assertFileExists($this->root . '/app/Blog/Http/Resources/PostResource.php');
    }

    /**
     * Test that a resource nested beneath a configured path does not warn.
     *
     * @return void
     */
    public function testResourceNestedBeneathAConfiguredPathDoesNotWarn(): void
    {
        Config::set('api-toolkit.resources.paths', [42, $this->root . '/app/Http/Resources/']);

        $this->pendingArtisan(self::COMMAND, ['name' => 'Admin/PostResource', '--model' => Post::class])
            ->doesntExpectOutputToContain('discovery')
            ->assertExitCode(0)
            ->run();
    }

    /**
     * Test that a path holding pattern characters is matched literally, both
     * under the default paths and as a configured path.
     *
     * @return void
     */
    public function testPathHoldingPatternCharactersIsMatchedLiterally(): void
    {
        assert($this->app !== null);

        $app = $this->root . '/[x]*?/app';

        mkdir($app, 0o755, true);

        $this->app->useAppPath($app);

        $this->pendingArtisan(self::COMMAND, ['name' => 'PostResource', '--model' => Post::class])
            ->doesntExpectOutputToContain('discovery')
            ->assertExitCode(0)
            ->run();

        Config::set('api-toolkit.resources.paths', [$app . '/Http/Resources']);

        $this->pendingArtisan(self::COMMAND, ['name' => 'PostResource', '--model' => Post::class, '--force' => true])
            ->doesntExpectOutputToContain('discovery')
            ->assertExitCode(0)
            ->run();

        self::assertFileExists($app . '/Http/Resources/PostResource.php');
    }

    /**
     * Test that a module root beneath a path holding pattern characters warns,
     * since discovery globs the module roots and misses it.
     *
     * @return void
     */
    public function testModuleRootBeneathAPathHoldingPatternCharactersWarns(): void
    {
        assert($this->app !== null);

        $app = $this->root . '/[x]/app';

        mkdir($app, 0o755, true);

        $this->app->useAppPath($app);

        $this->pendingArtisan(self::COMMAND, ['name' => 'App\Blog\Http\Resources\PostResource', '--model' => Post::class])
            ->expectsOutputToContain('is outside every resource discovery path')
            ->assertExitCode(0)
            ->run();

        self::assertSame([], glob(app_path('*/Http/Resources')));
    }

    /**
     * Test that the default module pattern matches a single directory level.
     *
     * @return void
     */
    public function testDefaultModulePatternMatchesOneDirectoryLevel(): void
    {
        $this->pendingArtisan(self::COMMAND, ['name' => 'App\Blog\Admin\Http\Resources\PostResource', '--model' => Post::class])
            ->expectsOutputToContain('is outside every resource discovery path')
            ->assertExitCode(0)
            ->run();
    }

    /**
     * Test that a compiled map served while schemas are not validated warns.
     *
     * @return void
     */
    public function testCompiledMapWarnsWithoutSchemaValidation(): void
    {
        $this->writeCompiledMap([]);

        $this->pendingArtisan(self::COMMAND, ['name' => 'PostResource', '--model' => Post::class])
            ->expectsOutputToContain('A compiled resource map exists, so run `php artisan api-toolkit:resources:cache` for discovery to serve the resource.')
            ->assertExitCode(0)
            ->run();
    }

    /**
     * Test that a compiled map ignored under schema validation does not warn.
     *
     * @return void
     */
    public function testCompiledMapDoesNotWarnUnderSchemaValidation(): void
    {
        $this->writeCompiledMap([]);

        Config::set('api-toolkit.resources.validate_schemas', true);

        $this->pendingArtisan(self::COMMAND, ['name' => 'PostResource', '--model' => Post::class])
            ->doesntExpectOutputToContain('compiled resource map')
            ->assertExitCode(0)
            ->run();
    }

    /**
     * Test that dynamic morph mapping notes the alias an unaliased model gains.
     *
     * @return void
     */
    public function testMorphAliasIsNotedForAnUnaliasedModel(): void
    {
        Config::set('api-toolkit.resources.enable_dynamic_morph_mapping', true);

        $this->pendingArtisan(self::COMMAND, ['name' => 'PostResource', '--model' => Post::class])
            ->expectsOutputToContain(sprintf('Polymorphic relations will store [post] for [%s] instead of its class name; existing rows keep the class name.', Post::class))
            ->assertExitCode(0)
            ->run();
    }

    /**
     * Test that no morph alias is noted when the alias or the model is already
     * mapped, or dynamic morph mapping is off.
     *
     * @return void
     */
    public function testMorphAliasIsNotNotedWhenMappedOrDisabled(): void
    {
        $cases = [
            [true, ['post' => Tag::class]],
            [true, ['article' => Post::class]],
            [false, []],
        ];

        foreach ($cases as [$enabled, $morphMap]) {

            Config::set('api-toolkit.resources.enable_dynamic_morph_mapping', $enabled);
            Relation::morphMap($morphMap, false);

            $this->pendingArtisan(self::COMMAND, ['name' => 'PostResource', '--model' => Post::class, '--force' => true])
                ->doesntExpectOutputToContain('Polymorphic')
                ->assertExitCode(0)
                ->run();
        }
    }

    /**
     * Test that an existing file is refused without force and replaced with it.
     *
     * @return void
     */
    public function testExistingFileIsReplacedOnlyUnderForce(): void
    {
        $this->generate('Http/Resources/PostResource.php', 'existing');

        $this->pendingArtisan(self::COMMAND, ['name' => 'PostResource', '--model' => Post::class])
            ->expectsOutputToContain('Resource already exists.')
            ->assertExitCode(1)
            ->run();

        self::assertSame('existing', $this->generated('Http/Resources/PostResource.php'));

        $this->pendingArtisan(self::COMMAND, ['name' => 'PostResource', '--model' => Post::class, '--force' => true])
            ->assertExitCode(0)
            ->run();

        self::assertStringContainsString('class PostResource extends ApiResource', $this->generated('Http/Resources/PostResource.php'));
    }

    /**
     * Test that a stub published to the application stubs directory is used.
     *
     * @return void
     */
    public function testPublishedStubOverridesThePackageStub(): void
    {
        assert($this->app !== null);

        $this->app->setBasePath($this->root);

        $this->generate('../stubs/api-toolkit.resource.stub', "<?php\n\n// {{ class }} {{ type }} {{ model }}\n");

        $this->pendingArtisan(self::COMMAND, ['name' => 'PostResource', '--model' => Post::class])->assertExitCode(0)->run();

        self::assertSame("<?php\n\n// PostResource 'post' Post\n", $this->generated('Http/Resources/PostResource.php'));
    }

    /**
     * Test that a published stub saved with Windows line endings still drops
     * the model import when the model is referenced by its qualified name.
     *
     * @return void
     */
    public function testPublishedStubWithWindowsLineEndingsDropsTheModelImport(): void
    {
        assert($this->app !== null);

        $this->app->setBasePath($this->root);

        $stub = file_get_contents(MakeResourceCommand::STUB);

        assert($stub !== false);

        $this->generate('../stubs/api-toolkit.resource.stub', str_replace("\n", "\r\n", $stub));

        $this->pendingArtisan(self::COMMAND, ['name' => 'FieldResource', '--model' => FieldModel::class])->assertExitCode(0)->run();

        self::assertStringNotContainsString('use ' . FieldModel::class . ';', $this->generated('Http/Resources/FieldResource.php'));
        $this->assertLints($this->root . '/app/Http/Resources/FieldResource.php');
    }

    /**
     * Test that publishing the stubs copies the package stub.
     *
     * @return void
     */
    public function testStubPublishCopiesThePackageStub(): void
    {
        assert($this->app !== null);

        $this->app->setBasePath($this->root);

        $this->pendingArtisan('stub:publish')->assertExitCode(0)->run();

        self::assertFileEquals(MakeResourceCommand::STUB, $this->root . '/stubs/api-toolkit.resource.stub');
    }

    /**
     * Serialise the model through the given resource for a request with the
     * given query.
     *
     * @param  string  $class
     * @param  \Tests\Fixtures\Models\Post  $post
     * @param  array<string, mixed>  $query
     * @return array<array-key, mixed>
     */
    private function serialise(string $class, Post $post, array $query): array
    {
        ApiQuery::parse(Request::create('/test', 'GET', $query));

        $resource = new $class($post);

        assert($resource instanceof ApiResource);

        return $resource->resolve();
    }

    /**
     * Get the qualified name of a class generated under the default namespace.
     *
     * @param  string  $name
     * @return string
     */
    private function generatedClass(string $name): string
    {
        return self::NAMESPACE . $name;
    }

    /**
     * Write and load a class declared at runtime beneath the application path.
     *
     * @param  string  $relative
     * @param  string  $declaration
     * @return void
     */
    private function declareClass(string $relative, string $declaration): void
    {
        $path = $this->generate($relative, "<?php\n\n" . $declaration . "\n");

        require_once $path;
    }

    /**
     * Write the given contents beneath the application path.
     *
     * @param  string  $relative
     * @param  string  $contents
     * @return string
     */
    private function generate(string $relative, string $contents): string
    {
        $path = $this->root . '/app/' . $relative;

        (new Filesystem)->ensureDirectoryExists(dirname($path));

        file_put_contents($path, $contents);

        return $path;
    }

    /**
     * Read a generated file beneath the application path.
     *
     * @param  string  $relative
     * @return string
     */
    private function generated(string $relative): string
    {
        $contents = file_get_contents($this->root . '/app/' . $relative);

        assert($contents !== false);

        return $contents;
    }

    /**
     * Assert that the given file compiles.
     *
     * @param  string  $path
     * @return void
     */
    private function assertLints(string $path): void
    {
        $process = new Process([PHP_BINARY, '-l', $path]);
        $process->run();

        self::assertSame(0, $process->getExitCode(), $process->getOutput() . $process->getErrorOutput());
    }

    /**
     * Resolve the absolute path to a discovery fixture directory.
     *
     * @param  string  $directory
     * @return string
     */
    private function discoveryFixture(string $directory): string
    {
        return dirname(__DIR__, 2) . '/Fixtures/Discovery/' . $directory;
    }
}
