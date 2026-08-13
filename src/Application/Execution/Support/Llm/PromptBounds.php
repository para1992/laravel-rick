<?php

declare(strict_types=1);

namespace Rick\Laravel\Application\Execution\Support\Llm;

use InvalidArgumentException;
use Rick\Laravel\Domain\Llm\ValueObject\CompletionRequest;
use Rick\Laravel\Domain\Llm\ValueObject\Message;

final readonly class PromptBounds
{
    public function __construct(private int $maxCharacters = 100_000)
    {
        if ($maxCharacters < 1_000) {
            throw new InvalidArgumentException('Prompt character limit must be at least 1000.');
        }
    }

    public function apply(CompletionRequest $request): CompletionRequest
    {
        $original = array_sum(array_map(
            static fn (Message $message): int => mb_strlen($message->content),
            $request->messages,
        ));
        if ($original <= $this->maxCharacters) {
            return $request;
        }

        $system = array_sum(array_map(
            static fn (Message $message): int => $message->role === 'system'
                ? mb_strlen($message->content)
                : 0,
            $request->messages,
        ));
        if ($system >= $this->maxCharacters) {
            throw new InvalidArgumentException('System prompts exceed the configured prompt character limit.');
        }

        $remaining = $this->maxCharacters - $system;
        $messages = [];
        foreach ($request->messages as $message) {
            if ($message->role === 'system') {
                $messages[] = $message;

                continue;
            }
            $included = min($remaining, mb_strlen($message->content));
            $content = $included < mb_strlen($message->content)
                ? $this->boundedContent($message->content, $included)
                : $message->content;
            $messages[] = new Message($message->role, $content);
            $remaining -= mb_strlen($content);
        }

        $includedCharacters = array_sum(array_map(
            static fn (Message $message): int => mb_strlen($message->content),
            $messages,
        ));

        return new CompletionRequest(
            $messages,
            $request->responseContract,
            $request->purpose,
            $request->modelTier,
            $request->options,
            $request->textResponsePolicy,
            [
                'prompt_bounds' => [
                    'original_characters' => $original,
                    'included_characters' => $includedCharacters,
                    'truncated' => true,
                    'source_hash' => hash('sha256', implode("\n", array_map(
                        static fn (Message $message): string => $message->content,
                        $request->messages,
                    ))),
                ],
            ] + $request->metadata,
            $request->responseSchema,
            $request->structuredResponseAttempts,
        );
    }

    private function boundedContent(string $content, int $limit): string
    {
        $structured = $this->compactStructuredContent($content, $limit);
        if ($structured !== null) {
            return $structured;
        }
        if ($limit <= 24) {
            return mb_substr($content, 0, $limit);
        }

        $marker = "\n[TRUNCATED]";

        return mb_substr($content, 0, $limit - mb_strlen($marker)).$marker;
    }

    private function compactStructuredContent(string $content, int $limit): ?string
    {
        $separator = strrpos($content, "\n\n{");
        if ($separator === false) {
            return null;
        }
        $prefix = substr($content, 0, $separator);
        $decoded = json_decode(substr($content, $separator + 2), true);
        if (! is_array($decoded) || array_is_list($decoded)) {
            return null;
        }

        if (is_string($decoded['task'] ?? null) && trim($decoded['task']) === trim($prefix)) {
            $decoded['task'] = '[DUPLICATED BEFORE JSON]';
        }
        $inputs = $decoded['inputs'] ?? null;
        if (is_array($inputs) && ! array_is_list($inputs)) {
            foreach ($inputs as $key => $input) {
                if (! is_string($key) || ! is_array($input) || array_is_list($input)) {
                    continue;
                }
                $artifactContent = $input['content'] ?? null;
                if (! is_string($artifactContent) || $artifactContent === '') {
                    continue;
                }
                if (isset($input['payload'])) {
                    $decodedContent = json_decode($artifactContent, true);
                    $input['payload'] = is_array($decodedContent)
                        && $decodedContent === $input['payload']
                            ? '[DUPLICATED IN ARTIFACT CONTENT]'
                            : self::replaceDuplicateStrings(
                                $input['payload'],
                                $artifactContent,
                            );
                }
                if (mb_strlen($artifactContent) >= 256 && str_contains($prefix, $artifactContent)) {
                    $prefix = str_replace(
                        $artifactContent,
                        "[DUPLICATED IN INPUTS.{$key}.CONTENT]",
                        $prefix,
                    );
                }
                $inputs[$key] = $input;
            }
            $decoded['inputs'] = $inputs;
        }

        $json = json_encode(
            $decoded,
            JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT,
        );
        $bounded = $prefix."\n\n".$json;
        if (mb_strlen($bounded) > $limit) {
            throw new InvalidArgumentException(
                'Structured prompt exceeds the configured character limit after duplicate compaction.',
            );
        }

        return $bounded;
    }

    private static function replaceDuplicateStrings(mixed $value, string $duplicate): mixed
    {
        if (is_string($value)) {
            return $value === $duplicate ? '[DUPLICATED IN ARTIFACT CONTENT]' : $value;
        }
        if (! is_array($value)) {
            return $value;
        }

        return array_map(
            static fn (mixed $item): mixed => self::replaceDuplicateStrings($item, $duplicate),
            $value,
        );
    }
}
