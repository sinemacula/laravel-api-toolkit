<?php

declare(strict_types = 1);

namespace Tests\Unit\Schema\Validation\Rules;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use SineMacula\ApiToolkit\Contracts\ApiResourceInterface;
use SineMacula\ApiToolkit\Schema\CompiledFieldDefinition;
use SineMacula\ApiToolkit\Schema\CompiledSchema;
use SineMacula\ApiToolkit\Schema\Validation\Rules\ValidateRelationInterfaces;
use Tests\Fixtures\Resources\OrganizationResource;

/**
 * Tests for the ValidateRelationInterfaces validation rule.
 *
 * @author      Ben Carey <bdmc@sinemacula.co.uk>
 * @copyright   2026 Sine Macula Limited.
 *
 * @SuppressWarnings("php:S1192")
 *
 * @internal
 */
#[CoversClass(ValidateRelationInterfaces::class)]
final class ValidateRelationInterfacesTest extends TestCase
{
    /**
     * Test no errors when relation class implements interface.
     *
     * @return void
     */
    public function testNoErrorsWhenRelationClassImplementsInterface(): void
    {
        $schema = new CompiledSchema(
            fields: [
                'organization' => new CompiledFieldDefinition(
                    accessor: 'organization',
                    compute: null,
                    relation: 'organization',
                    resource: OrganizationResource::class,
                    fields: null,
                    constraint: null,
                    extras: [],
                    needs: [],
                    guards: [],
                    transformers: [],
                ),
            ],
        );

        $rule   = new ValidateRelationInterfaces;
        $errors = $rule->validate('App\Http\Resources\UserResource', null, $schema);

        self::assertSame([], $errors);
    }

    /**
     * Test reports relation class not implementing interface.
     *
     * @return void
     */
    public function testReportsRelationClassNotImplementingInterface(): void
    {
        $schema = new CompiledSchema(
            fields: [
                'organization' => new CompiledFieldDefinition(
                    accessor: 'organization',
                    compute: null,
                    relation: 'organization',
                    resource: \stdClass::class,
                    fields: null,
                    constraint: null,
                    extras: [],
                    needs: [],
                    guards: [],
                    transformers: [],
                ),
            ],
        );

        $rule   = new ValidateRelationInterfaces;
        $errors = $rule->validate('App\Http\Resources\UserResource', null, $schema);

        self::assertCount(1, $errors);
        self::assertSame('organization', $errors[0]->fieldKey);
        self::assertStringContainsString(\stdClass::class, $errors[0]->defect);
        self::assertStringContainsString(ApiResourceInterface::class, $errors[0]->defect);
    }

    /**
     * Test skips non-existent classes in interface validation.
     *
     * @return void
     */
    public function testSkipsNonExistentClassesInInterfaceValidation(): void
    {
        $schema = new CompiledSchema(
            fields: [
                'organization' => new CompiledFieldDefinition(
                    accessor: 'organization',
                    compute: null,
                    relation: 'organization',
                    resource: 'App\NonExistent\Resource',
                    fields: null,
                    constraint: null,
                    extras: [],
                    needs: [],
                    guards: [],
                    transformers: [],
                ),
            ],
        );

        $rule   = new ValidateRelationInterfaces;
        $errors = $rule->validate('App\Http\Resources\UserResource', null, $schema);

        self::assertSame([], $errors);
    }

    /**
     * Test that validation continues past non-existent classes.
     *
     * @return void
     */
    public function testContinuesPastNonExistentClasses(): void
    {
        $schema = new CompiledSchema(
            fields: [
                'missing' => $this->makeField('App\NonExistent\Resource'),
                'bad'     => $this->makeField(\stdClass::class),
            ],
        );

        $rule   = new ValidateRelationInterfaces;
        $errors = $rule->validate('App\Http\Resources\UserResource', null, $schema);

        self::assertCount(1, $errors);
        self::assertSame('bad', $errors[0]->fieldKey);
    }

    /**
     * Test that validation continues past a conforming relation class.
     *
     * @return void
     */
    public function testContinuesPastConformingRelationClass(): void
    {
        $schema = new CompiledSchema(
            fields: [
                'good' => $this->makeField(OrganizationResource::class),
                'bad'  => $this->makeField(\stdClass::class),
            ],
        );

        $rule   = new ValidateRelationInterfaces;
        $errors = $rule->validate('App\Http\Resources\UserResource', null, $schema);

        self::assertCount(1, $errors);
        self::assertSame('bad', $errors[0]->fieldKey);
    }

    /**
     * Test that every non-conforming relation class is reported.
     *
     * @return void
     */
    public function testReportsAllNonConformingRelationClasses(): void
    {
        $schema = new CompiledSchema(
            fields: [
                'first'  => $this->makeField(\stdClass::class),
                'second' => $this->makeField(\SplStack::class),
            ],
        );

        $rule   = new ValidateRelationInterfaces;
        $errors = $rule->validate('App\Http\Resources\UserResource', null, $schema);

        self::assertCount(2, $errors);
        self::assertSame('first', $errors[0]->fieldKey);
        self::assertSame('second', $errors[1]->fieldKey);
    }

    /**
     * Create a compiled field definition with the given resource class.
     *
     * @param  string  $resource
     * @return \SineMacula\ApiToolkit\Schema\CompiledFieldDefinition
     */
    private function makeField(string $resource): CompiledFieldDefinition
    {
        return new CompiledFieldDefinition(
            accessor: 'relation',
            compute: null,
            relation: 'relation',
            resource: $resource,
            fields: null,
            constraint: null,
            extras: [],
            needs: [],
            guards: [],
            transformers: [],
        );
    }
}
