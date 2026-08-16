<?php

declare(strict_types=1);

namespace Rick\Laravel\Tests\Unit\Domain;

use PHPUnit\Framework\TestCase;
use Rick\Laravel\Application\Execution\Exception\StructuredResponseException;
use Rick\Laravel\Application\Execution\Support\Quality\ContentDistinctness;
use Rick\Laravel\Application\Execution\Support\Schema\CompletionResponseValidator;
use Rick\Laravel\Application\Execution\Support\Schema\ResponseSchemaResolver;
use Rick\Laravel\Application\Execution\Support\Schema\StructuredResponseDecoder;
use Rick\Laravel\Application\Interface\JsonSchemaValidatorBase;
use Rick\Laravel\Domain\Execution\ValueObject\StructuredDecodeStatus;
use Rick\Laravel\Domain\Llm\ValueObject\CompletionRequest;
use Rick\Laravel\Domain\Llm\ValueObject\CompletionResponse;
use Rick\Laravel\Domain\Llm\ValueObject\ResponseContract;
use Rick\Laravel\Infrastructure\Schema\JsonSchemaValidator;
use RuntimeException;

final class CompletionResponseSchemaTest extends TestCase
{
    public function test_every_package_contract_resolves_and_text_and_generic_json_require_explicit_schemas(): void
    {
        $resolver = new ResponseSchemaResolver(new JsonSchemaValidator);
        $stringList = ['type' => 'array', 'items' => ['type' => 'string']];
        $object = static fn (array $properties): array => [
            'type' => 'object',
            'properties' => $properties,
            'required' => array_keys($properties),
            'additionalProperties' => false,
        ];
        $candidate = $object(['content' => ['type' => 'string']]);
        $expected = [
            ResponseContract::Candidate->value => $candidate,
            ResponseContract::PlanCandidate->value => $candidate,
            ResponseContract::MemoryCandidate->value => $object([
                'content' => ['type' => 'string'],
                'memory_delta' => $object([
                    'facts_added' => $stringList,
                    'decisions_added' => $stringList,
                    'loops_opened' => $stringList,
                    'loops_resolved' => $stringList,
                    'requirements_covered' => $stringList,
                    'requirements_violated' => $stringList,
                ]),
            ]),
            ResponseContract::Judge->value => $object([
                'selected_candidate_id' => ['type' => 'string'],
                'score' => ['type' => 'number', 'minimum' => 0, 'maximum' => 100],
                'reason' => ['type' => 'string'],
            ]),
            ResponseContract::UnfoldUnits->value => $object([
                'units' => [
                    'type' => 'array',
                    'items' => $object([
                        'unit_id' => ['type' => 'string'],
                        'title' => ['type' => 'string'],
                        'source_order' => ['type' => 'integer'],
                        'content' => ['type' => 'string'],
                        'constraints' => $stringList,
                        'must_preserve' => $stringList,
                        'dependencies' => $stringList,
                        'must_cover' => $stringList,
                        'must_not_repeat' => $stringList,
                        'memory_reads' => $stringList,
                        'memory_writes' => $stringList,
                    ]),
                ],
            ]),
            ResponseContract::DefinitionOfDone->value => $object(['criteria' => $stringList]),
        ];

        foreach ($expected as $contractValue => $expectedSchema) {
            $contract = ResponseContract::from($contractValue);
            $schema = $resolver->for(new CompletionRequest([], $contract, $contract->value));
            self::assertSame($expectedSchema, $schema, $contract->value);
        }

        foreach ([
            ResponseContract::Text->value => 'Text completions do not have a structured output schema.',
            ResponseContract::Json->value => 'The generic JSON response contract requires a response schema.',
        ] as $contractValue => $message) {
            $contract = ResponseContract::from($contractValue);
            try {
                $resolver->for(new CompletionRequest([], $contract, $contract->value));
                self::fail('A contract without a structured schema was accepted.');
            } catch (\InvalidArgumentException $error) {
                self::assertSame($message, $error->getMessage());
            }
        }
    }

    public function test_resolver_validates_the_exact_schema_and_fingerprint_preserves_unicode_and_slashes(): void
    {
        $schema = [
            'type' => 'object',
            'properties' => ['content' => ['type' => 'string', 'description' => 'żółć/a']],
            'required' => ['content'],
            'additionalProperties' => false,
        ];
        $validator = $this->createMock(JsonSchemaValidatorBase::class);
        $validator->expects(self::exactly(2))->method('assertSchema')->with($schema);
        $resolver = new ResponseSchemaResolver($validator);
        $request = new CompletionRequest([], ResponseContract::Json, 'unicode_schema', responseSchema: $schema);

        self::assertSame($schema, $resolver->for($request));
        self::assertSame(
            hash('sha256', json_encode(
                $schema,
                JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES,
            )),
            $resolver->fingerprint($request),
        );
    }

    public function test_strict_schema_walker_rejects_every_malformed_container_shape(): void
    {
        $resolver = new ResponseSchemaResolver(self::createStub(JsonSchemaValidatorBase::class));
        $base = [
            'type' => 'object',
            'properties' => ['value' => ['type' => 'string']],
            'required' => ['value'],
            'additionalProperties' => false,
        ];
        $cases = [
            ['type' => 'object', 'properties' => [], 'required' => [], 'additionalProperties' => false],
            ['type' => 'object', 'properties' => ['value'], 'required' => ['value'], 'additionalProperties' => false],
            ['type' => 'object', 'properties' => ['' => ['type' => 'string']], 'required' => [''], 'additionalProperties' => false],
            ['type' => 'object', 'properties' => ['value' => 'string'], 'required' => ['value'], 'additionalProperties' => false],
            ['type' => 'object', 'properties' => ['value' => ['type' => 'string']], 'required' => 'value', 'additionalProperties' => false],
            ['type' => 'object', 'properties' => ['value' => ['type' => 'string']], 'required' => [1], 'additionalProperties' => false],
            ['type' => 'object', 'properties' => ['value' => ['type' => 'string']], 'required' => [], 'additionalProperties' => false],
            ['type' => 'object', 'properties' => ['value' => ['type' => 'string']], 'required' => ['value'], 'additionalProperties' => true],
            $base + ['items' => 'invalid'],
            $base + ['allOf' => 'invalid'],
            $base + ['anyOf' => ['invalid']],
            $base + ['$defs' => []],
            $base + ['definitions' => ['branch' => 'invalid']],
            $base + ['contains' => 'invalid'],
            $base + ['prefixItems' => 'invalid'],
            $base + ['prefixItems' => ['invalid']],
        ];

        foreach ($cases as $index => $schema) {
            try {
                $resolver->for(new CompletionRequest(
                    [],
                    ResponseContract::Json,
                    'invalid_'.$index,
                    responseSchema: $schema,
                ));
                self::fail("Malformed schema {$index} was accepted.");
            } catch (\InvalidArgumentException $error) {
                self::assertStringContainsString('Structured output', $error->getMessage());
            }
        }
    }

    public function test_strict_schema_walker_visits_compositions_maps_conditionals_and_prefix_items(): void
    {
        $resolver = new ResponseSchemaResolver(self::createStub(JsonSchemaValidatorBase::class));
        $leaf = [
            'type' => 'object',
            'properties' => ['value' => ['type' => 'string']],
            'required' => ['value'],
            'additionalProperties' => false,
        ];
        $schema = [
            'type' => ['null', 'object'],
            'properties' => [
                'items' => ['type' => 'array', 'items' => $leaf],
            ],
            'required' => ['items'],
            'additionalProperties' => false,
            'allOf' => [$leaf],
            'anyOf' => [$leaf],
            'oneOf' => [$leaf],
            '$defs' => ['defined' => $leaf],
            'definitions' => ['legacy' => $leaf],
            'patternProperties' => ['^x' => $leaf],
            'dependentSchemas' => ['items' => $leaf],
            'contains' => $leaf,
            'not' => $leaf,
            'if' => $leaf,
            'then' => $leaf,
            'else' => $leaf,
            'propertyNames' => ['type' => 'string'],
            'prefixItems' => [$leaf],
        ];

        self::assertSame($schema, $resolver->for(new CompletionRequest(
            [],
            ResponseContract::Json,
            'deep_schema',
            responseSchema: $schema,
        )));
    }

    public function test_structured_contract_rejects_missing_required_content(): void
    {
        $validator = new JsonSchemaValidator;
        $resolver = new ResponseSchemaResolver($validator);
        $schemas = new CompletionResponseValidator(
            $validator,
            $resolver,
            new StructuredResponseDecoder($resolver),
            new ContentDistinctness,
        );

        $this->expectException(RuntimeException::class);
        $schemas->assert(
            new CompletionRequest([], ResponseContract::Candidate, 'candidate'),
            new CompletionResponse(structured: ['title' => 'Missing content']),
        );
    }

    public function test_defensive_decoder_preserves_every_safe_failure_category(): void
    {
        $validator = new JsonSchemaValidator;
        $resolver = new ResponseSchemaResolver($validator);
        $decoder = new StructuredResponseDecoder($resolver);
        $request = new CompletionRequest([], ResponseContract::Candidate, 'candidate');
        $cases = [
            ['', StructuredDecodeStatus::Empty, null, false],
            [" \n\t", StructuredDecodeStatus::Empty, null, false],
            ['{"content":', StructuredDecodeStatus::InvalidJson, null, true],
            ['"text"', StructuredDecodeStatus::Scalar, 'string', true],
            ['42', StructuredDecodeStatus::Scalar, 'integer', true],
            ['true', StructuredDecodeStatus::Scalar, 'boolean', true],
            ['null', StructuredDecodeStatus::Scalar, 'null', true],
            ['[]', StructuredDecodeStatus::Array, 'array', true],
            ['{}', StructuredDecodeStatus::Object, 'object', true],
            ['{"content":"valid"}', StructuredDecodeStatus::Object, 'object', true],
            ["```json\n{\"content\":\"fenced\"}\n```", StructuredDecodeStatus::Object, 'object', true],
        ];

        foreach ($cases as [$raw, $status, $rootType, $present]) {
            $inspection = $decoder->decode($request, $raw, 'stop', false, false);
            self::assertSame($status, $inspection->diagnostic->decodeStatus, $raw);
            self::assertSame($rootType, $inspection->diagnostic->decodedRootType, $raw);
            self::assertSame($present, $inspection->diagnostic->responsePresent, $raw);
            self::assertSame(strlen($raw), $inspection->diagnostic->responseBytes, $raw);
            self::assertFalse($inspection->diagnostic->usagePresent);
            self::assertFalse($inspection->diagnostic->usageComplete);
        }
        $secret = 'decode-secret-marker';
        $diagnostic = $decoder->decode(
            $request,
            '{"content":"'.$secret.'"',
            null,
            false,
            false,
        )->diagnostic;
        self::assertStringNotContainsString(
            $secret,
            json_encode($diagnostic, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE),
        );
    }

    public function test_schema_validation_diagnostic_preserves_path_and_keyword_without_raw_content(): void
    {
        $validator = new JsonSchemaValidator;
        $resolver = new ResponseSchemaResolver($validator);
        $decoder = new StructuredResponseDecoder($resolver);
        $responses = new CompletionResponseValidator(
            $validator,
            $resolver,
            $decoder,
            new ContentDistinctness,
        );
        $request = new CompletionRequest([], ResponseContract::Candidate, 'candidate');
        $secret = 'schema-secret-marker';
        $inspection = $decoder->decode(
            $request,
            json_encode(['content' => 7, 'secret' => $secret], JSON_THROW_ON_ERROR),
            'stop',
            true,
            true,
        );

        try {
            $responses->assert($request, new CompletionResponse(
                structured: $inspection->value,
                diagnostic: $inspection->diagnostic,
            ));
            self::fail('Schema-invalid structured output was accepted.');
        } catch (StructuredResponseException $error) {
            self::assertSame('schema_validation', $error->diagnostic->stage->value);
            self::assertNotNull($error->diagnostic->validationPath);
            self::assertContains($error->diagnostic->validationKeyword, [
                'type',
                'additionalProperties',
            ]);
            self::assertStringNotContainsString(
                $secret,
                json_encode($error->diagnostic, JSON_THROW_ON_ERROR),
            );
        }
    }

    public function test_schema_fingerprint_is_canonical_and_the_same_schema_validates_inbound(): void
    {
        $validator = new JsonSchemaValidator;
        $resolver = new ResponseSchemaResolver($validator);
        $decoder = new StructuredResponseDecoder($resolver);
        $responses = new CompletionResponseValidator(
            $validator,
            $resolver,
            $decoder,
            new ContentDistinctness,
        );
        $request = new CompletionRequest([], ResponseContract::Candidate, 'candidate');
        $schema = $resolver->for($request);
        $inspection = $decoder->decode($request, '{"content":"accepted"}', null, true, true);

        self::assertSame([
            'type' => 'object',
            'properties' => ['content' => ['type' => 'string']],
            'required' => ['content'],
            'additionalProperties' => false,
        ], $schema);
        self::assertSame(
            'ed8501c67fcda6a397f7e696d276ed961d9af95a9f349f858f65fbecb6386401',
            $resolver->fingerprint($request),
        );
        self::assertSame($resolver->fingerprint($request), $inspection->diagnostic->schemaFingerprint);
        $responses->assert($request, new CompletionResponse(
            structured: $inspection->value,
            diagnostic: $inspection->diagnostic,
        ));
        self::assertSame(['content' => 'accepted'], $inspection->value);
    }

    public function test_custom_structured_schema_requires_a_strict_object_at_every_level(): void
    {
        $validator = new JsonSchemaValidator;
        $resolver = new ResponseSchemaResolver($validator);

        foreach ([
            ['type' => 'array', 'items' => ['type' => 'string']],
            [
                'type' => 'object',
                'properties' => ['content' => ['type' => 'string']],
                'required' => ['content'],
            ],
            [
                'type' => 'object',
                'properties' => ['payload' => ['$ref' => '#/$defs/payload']],
                'required' => ['payload'],
                'additionalProperties' => false,
                '$defs' => [
                    'payload' => [
                        'type' => 'object',
                        'properties' => ['content' => ['type' => 'string']],
                        'required' => ['content'],
                    ],
                ],
            ],
        ] as $schema) {
            try {
                $resolver->for(new CompletionRequest(
                    [],
                    ResponseContract::Json,
                    'strict_custom_schema',
                    responseSchema: $schema,
                ));
                self::fail('A non-strict structured output schema was accepted.');
            } catch (\InvalidArgumentException $error) {
                self::assertStringContainsString('Structured output', $error->getMessage());
            }
        }

        $strict = [
            'type' => 'object',
            'properties' => ['payload' => ['$ref' => '#/$defs/payload']],
            'required' => ['payload'],
            'additionalProperties' => false,
            '$defs' => [
                'payload' => [
                    'type' => 'object',
                    'properties' => ['content' => ['type' => 'string']],
                    'required' => ['content'],
                    'additionalProperties' => false,
                ],
            ],
        ];
        self::assertSame($strict, $resolver->for(new CompletionRequest(
            [],
            ResponseContract::Json,
            'strict_custom_schema',
            responseSchema: $strict,
        )));
    }

    public function test_validator_rejects_non_object_and_non_serializable_structured_responses(): void
    {
        $validator = new JsonSchemaValidator;
        $resolver = new ResponseSchemaResolver($validator);
        $responses = new CompletionResponseValidator(
            $validator,
            $resolver,
            new StructuredResponseDecoder($resolver),
            new ContentDistinctness,
        );
        $request = new CompletionRequest([], ResponseContract::Candidate, 'candidate');

        foreach ([
            new CompletionResponse(text: '[]'),
            new CompletionResponse(structured: ['content' => "\xFF"]),
        ] as $response) {
            try {
                $responses->assert($request, $response);
                self::fail('A non-object structured response was accepted.');
            } catch (StructuredResponseException $error) {
                self::assertStringContainsString('could not be decoded', $error->getMessage());
            }
        }
    }

    public function test_validator_rejects_source_restatement_and_ignores_non_object_policies(): void
    {
        $validator = new JsonSchemaValidator;
        $resolver = new ResponseSchemaResolver($validator);
        $distinctness = new ContentDistinctness;
        $responses = new CompletionResponseValidator(
            $validator,
            $resolver,
            new StructuredResponseDecoder($resolver),
            $distinctness,
        );
        $source = 'Mara enters the observatory and discovers the broken emergency radio';
        $request = new CompletionRequest(
            [],
            ResponseContract::Candidate,
            'candidate',
            metadata: [
                'content_distinctness' => [1 => 'not-an-object'],
                'source_unit_signature' => $distinctness->signature($source),
            ],
        );

        try {
            $responses->assert($request, new CompletionResponse(structured: ['content' => $source]));
            self::fail('A source restatement was accepted.');
        } catch (StructuredResponseException $error) {
            self::assertStringContainsString('restates the source', $error->getMessage());
            self::assertSame('source_restatement', $error->diagnostic->validationKeyword);
        }
    }

    public function test_validator_requires_every_declared_literal_in_structured_content(): void
    {
        $validator = new JsonSchemaValidator;
        $resolver = new ResponseSchemaResolver($validator);
        $responses = new CompletionResponseValidator(
            $validator,
            $resolver,
            new StructuredResponseDecoder($resolver),
            new ContentDistinctness,
        );
        $request = new CompletionRequest(
            [],
            ResponseContract::Candidate,
            'candidate',
            metadata: ['required_literals' => ['CLOCK_0317']],
        );

        try {
            $responses->assert($request, new CompletionResponse(structured: [
                'content' => 'The detective finds a clock with a suspicious time.',
            ]));
            self::fail('A completion that omitted a required literal was accepted.');
        } catch (StructuredResponseException $error) {
            self::assertSame('required_literal_missing', $error->diagnostic->validationKeyword);
        }

        self::assertNotNull($responses->assert($request, new CompletionResponse(structured: [
            'content' => 'The detective records CLOCK_0317 without changing it.',
        ])));
    }
}
