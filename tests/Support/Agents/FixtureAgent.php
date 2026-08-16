<?php

declare(strict_types=1);

namespace Rick\Laravel\Tests\Support\Agents;

use Illuminate\Broadcasting\Channel;
use Laravel\Ai\Contracts\Agent;
use Laravel\Ai\Enums\Lab;
use Laravel\Ai\Responses\AgentResponse;
use Laravel\Ai\Responses\QueuedAgentResponse;
use Laravel\Ai\Responses\StreamableAgentResponse;
use LogicException;
use Stringable;

/**
 * A minimal Laravel AI Agent implementation for test fixtures. Rick adapts the
 * agent through its instructions() only; the transport methods must never be
 * invoked, so they throw.
 */
abstract class FixtureAgent implements Agent
{
    abstract public function instructions(): Stringable|string;

    /**
     * @param  array<mixed, mixed>  $attachments
     * @param  Lab|array<mixed, mixed>|string|null  $provider
     */
    public function prompt(
        mixed $prompt,
        array $attachments = [],
        Lab|array|string|null $provider = null,
        ?string $model = null,
        ?int $timeout = null,
    ): AgentResponse {
        throw new LogicException('Fixture agents must never be invoked directly.');
    }

    /**
     * @param  array<mixed, mixed>  $attachments
     * @param  Lab|array<mixed, mixed>|string|null  $provider
     */
    public function stream(
        mixed $prompt,
        array $attachments = [],
        Lab|array|string|null $provider = null,
        ?string $model = null,
        ?int $timeout = null,
    ): StreamableAgentResponse {
        throw new LogicException('Fixture agents must never be invoked directly.');
    }

    /**
     * @param  array<mixed, mixed>  $attachments
     * @param  Lab|array<mixed, mixed>|string|null  $provider
     */
    public function queue(
        mixed $prompt,
        array $attachments = [],
        Lab|array|string|null $provider = null,
        ?string $model = null,
    ): QueuedAgentResponse {
        throw new LogicException('Fixture agents must never be invoked directly.');
    }

    /**
     * @param  Channel|array<mixed, mixed>  $channels
     * @param  array<mixed, mixed>  $attachments
     * @param  Lab|array<mixed, mixed>|string|null  $provider
     */
    public function broadcast(
        mixed $prompt,
        Channel|array $channels,
        array $attachments = [],
        bool $now = false,
        Lab|array|string|null $provider = null,
        ?string $model = null,
    ): StreamableAgentResponse {
        throw new LogicException('Fixture agents must never be invoked directly.');
    }

    /**
     * @param  Channel|array<mixed, mixed>  $channels
     * @param  array<mixed, mixed>  $attachments
     * @param  Lab|array<mixed, mixed>|string|null  $provider
     */
    public function broadcastNow(
        mixed $prompt,
        Channel|array $channels,
        array $attachments = [],
        Lab|array|string|null $provider = null,
        ?string $model = null,
    ): StreamableAgentResponse {
        throw new LogicException('Fixture agents must never be invoked directly.');
    }

    /**
     * @param  Channel|array<mixed, mixed>  $channels
     * @param  array<mixed, mixed>  $attachments
     * @param  Lab|array<mixed, mixed>|string|null  $provider
     */
    public function broadcastOnQueue(
        mixed $prompt,
        Channel|array $channels,
        array $attachments = [],
        Lab|array|string|null $provider = null,
        ?string $model = null,
    ): QueuedAgentResponse {
        throw new LogicException('Fixture agents must never be invoked directly.');
    }
}
