<?php

declare(strict_types=1);

namespace Rick\Stand\Tests\Feature;

use Rick\Laravel\Application\Execution\Exception\ProviderRequestException;
use Rick\Laravel\Application\Execution\Support\Schema\ResponseSchemaResolver;
use Rick\Laravel\Application\Execution\Support\Schema\StructuredResponseDecoder;
use Rick\Laravel\Domain\Execution\ValueObject\ProviderRequestOutcome;
use Rick\Laravel\Domain\Execution\ValueObject\StructuredDecodeStatus;
use Rick\Laravel\Domain\Llm\ValueObject\CompletionRequest;
use Rick\Laravel\Domain\Llm\ValueObject\ResponseContract;
use Rick\Stand\Fixture\CassetteCatalog;
use Rick\Stand\Fixture\CassetteFakeGatewayFactory;
use Rick\Stand\Tests\TestCase;

final class ContractMatrixTest extends TestCase
{
    public function test_every_response_contract_has_a_strict_schema_or_text_policy(): void
    {
        $resolver = $this->application()->make(ResponseSchemaResolver::class);
        foreach (ResponseContract::cases() as $contract) {
            if ($contract === ResponseContract::Text) {
                continue;
            }
            $schema = $contract === ResponseContract::Json
                ? ['type' => 'object', 'properties' => ['value' => ['type' => 'string']], 'required' => ['value'], 'additionalProperties' => false]
                : null;
            $request = new CompletionRequest([], $contract, 'contract-matrix', responseSchema: $schema);
            self::assertSame('object', $resolver->for($request)['type']);
        }
    }

    public function test_structured_decoder_classifies_edge_cases_and_fenced_json(): void
    {
        $decoder = $this->application()->make(StructuredResponseDecoder::class);
        $request = new CompletionRequest([], ResponseContract::Candidate, 'decode-matrix');
        $cases = [
            ['', StructuredDecodeStatus::Empty],
            [" \n\t ", StructuredDecodeStatus::Empty],
            ['{broken', StructuredDecodeStatus::InvalidJson],
            ['null', StructuredDecodeStatus::Scalar],
            ['1', StructuredDecodeStatus::Scalar],
            ['[]', StructuredDecodeStatus::Array],
            ['{}', StructuredDecodeStatus::Object],
            ["```json\n{\"content\":\"ok\"}\n```", StructuredDecodeStatus::Object],
        ];
        foreach ($cases as [$payload, $expected]) {
            self::assertSame($expected, $decoder->decode($request, $payload, null, true, true)->diagnostic->decodeStatus);
        }
    }

    public function test_provider_outcomes_are_replayed_without_transport(): void
    {
        $gateway = (new CassetteFakeGatewayFactory)->make(
            new CassetteCatalog(dirname(__DIR__, 2).'/fixtures'),
            ['synthetic-provider-indeterminate'],
        );
        try {
            $gateway->complete(new CompletionRequest([], ResponseContract::Text, 'raw_prompt'));
            self::fail('Synthetic provider error must be replayed.');
        } catch (ProviderRequestException $error) {
            self::assertSame(ProviderRequestOutcome::Indeterminate, $error->outcome);
            self::assertFalse($error->retryable);
        }
    }
}
