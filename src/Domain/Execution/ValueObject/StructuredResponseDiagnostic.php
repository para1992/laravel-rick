<?php

declare(strict_types=1);

namespace Rick\Laravel\Domain\Execution\ValueObject;

use InvalidArgumentException;
use JsonSerializable;
use Rick\Laravel\Domain\Llm\ValueObject\ResponseContract;

final readonly class StructuredResponseDiagnostic implements JsonSerializable
{
    public function __construct(
        public StructuredResponseStage $stage,
        public ResponseContract $contract,
        public string $schemaFingerprint,
        public bool $responsePresent,
        public int $responseBytes,
        public ?string $responseFingerprint,
        public StructuredDecodeStatus $decodeStatus,
        public ?string $decodedRootType,
        public ?string $validationPath,
        public ?string $validationKeyword,
        public ?string $finishReason,
        public bool $usagePresent,
        public bool $usageComplete,
        public ?string $retryDecision = null,
    ) {
        if (preg_match('/^[a-f0-9]{64}$/', $schemaFingerprint) !== 1) {
            throw new InvalidArgumentException('Structured response schema fingerprint is invalid.');
        }
        if ($responseBytes < 0) {
            throw new InvalidArgumentException('Structured response byte count cannot be negative.');
        }
        if (
            $responseFingerprint !== null
            && preg_match('/^[a-f0-9]{64}$/', $responseFingerprint) !== 1
        ) {
            throw new InvalidArgumentException('Structured response fingerprint is invalid.');
        }
    }

    public function validationFailure(string $path, string $keyword): self
    {
        return new self(
            StructuredResponseStage::SchemaValidation,
            $this->contract,
            $this->schemaFingerprint,
            $this->responsePresent,
            $this->responseBytes,
            $this->responseFingerprint,
            $this->decodeStatus,
            $this->decodedRootType,
            $path,
            $keyword,
            $this->finishReason,
            $this->usagePresent,
            $this->usageComplete,
            $this->retryDecision,
        );
    }

    public function withRetryDecision(string $decision): self
    {
        return new self(
            $this->stage,
            $this->contract,
            $this->schemaFingerprint,
            $this->responsePresent,
            $this->responseBytes,
            $this->responseFingerprint,
            $this->decodeStatus,
            $this->decodedRootType,
            $this->validationPath,
            $this->validationKeyword,
            $this->finishReason,
            $this->usagePresent,
            $this->usageComplete,
            $decision,
        );
    }

    /** @return array<string, bool|int|string|null> */
    public function toArray(): array
    {
        return [
            'schema_version' => 1,
            'stage' => $this->stage->value,
            'contract' => $this->contract->value,
            'schema_fingerprint' => $this->schemaFingerprint,
            'response_present' => $this->responsePresent,
            'response_bytes' => $this->responseBytes,
            'response_fingerprint' => $this->responseFingerprint,
            'decode_status' => $this->decodeStatus->value,
            'decoded_root_type' => $this->decodedRootType,
            'validation_path' => $this->validationPath,
            'validation_keyword' => $this->validationKeyword,
            'finish_reason' => $this->finishReason,
            'usage_present' => $this->usagePresent,
            'usage_complete' => $this->usageComplete,
            'retry_decision' => $this->retryDecision,
        ];
    }

    /** @return array<string, bool|int|string|null> */
    public function jsonSerialize(): array
    {
        return $this->toArray();
    }
}
