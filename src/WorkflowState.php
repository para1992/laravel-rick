<?php

declare(strict_types=1);

namespace Rick\Laravel;

use JsonException;
use Rick\Laravel\Domain\Run\Artifact;
use Rick\Laravel\Domain\Run\RunInput;
use Rick\Laravel\Domain\Run\ValueObject\RunId;
use Rick\Laravel\Domain\Workflow\ValueObject\ArtifactType;

class WorkflowState
{
    /** @var array<string, mixed> */
    private array $map = [];

    /** @var array<string, Artifact> */
    private array $artifacts = [];

    /** @var array<string, true> */
    private array $touched = [];

    /**
     * @param  array<string, Artifact>  $artifacts
     */
    public function __construct(
        private readonly RunInput $input,
        array $artifacts = [],
        private readonly ?RunId $runId = null,
    ) {
        foreach ($artifacts as $artifact) {
            $this->artifacts[$artifact->key] = $artifact;
            $this->map[$artifact->key] = $artifact->payload !== []
                ? self::copyMap($artifact->payload)
                : $artifact->content;
        }
    }

    public function input(string $key, mixed $default = null): mixed
    {
        $values = $this->input->toArray();

        if (! array_key_exists($key, $values)) {
            return $default;
        }

        return $values[$key];
    }

    public function get(string $key, mixed $default = null): mixed
    {
        $value = $this->map;

        foreach (explode('.', $key) as $segment) {
            if (! is_array($value) || ! array_key_exists($segment, $value)) {
                return $default;
            }

            $value = $value[$segment];
        }

        return $value;
    }

    public function put(string $key, mixed $value): self
    {
        $segments = explode('.', $key);
        $top = array_shift($segments);

        $this->touched[$top] = true;

        if ($segments === []) {
            $this->map[$top] = $value;

            return $this;
        }

        $branch = is_array($this->map[$top] ?? null) ? $this->map[$top] : [];

        $this->map[$top] = self::writePath($branch, $segments, $value);

        return $this;
    }

    public function has(string $key): bool
    {
        $value = $this->map;

        foreach (explode('.', $key) as $segment) {
            if (! is_array($value) || ! array_key_exists($segment, $value)) {
                return false;
            }

            $value = $value[$segment];
        }

        return true;
    }

    public function forget(string $key): self
    {
        $segments = explode('.', $key);
        $top = array_shift($segments);

        $this->touched[$top] = true;

        if (! array_key_exists($top, $this->map)) {
            return $this;
        }

        if ($segments === []) {
            unset($this->map[$top]);

            return $this;
        }

        $branch = $this->map[$top];

        if (! is_array($branch)) {
            return $this;
        }

        $this->map[$top] = self::forgetPath($branch, $segments);

        return $this;
    }

    /** @return array<string, mixed> */
    public function all(): array
    {
        return $this->map;
    }

    public function artifact(string $key): ?Artifact
    {
        return $this->artifacts[$key] ?? null;
    }

    /**
     * @return list<Artifact>
     *
     * @throws JsonException
     */
    public function toArtifacts(): array
    {
        $artifacts = [];

        foreach ($this->touched as $key => $_) {
            if (! array_key_exists($key, $this->map)) {
                continue;
            }

            $value = $this->map[$key];

            $artifacts[] = new Artifact(
                $key,
                ArtifactType::fromString('application'),
                is_string($value)
                    ? $value
                    : json_encode($value, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE),
                is_array($value) ? $value : [],
                [],
                1,
            );
        }

        return $artifacts;
    }

    public function runId(): ?RunId
    {
        return $this->runId;
    }

    /**
     * @param  array<mixed, mixed>  $map
     * @return array<mixed, mixed>
     */
    private static function copyMap(array $map): array
    {
        $copy = [];

        foreach ($map as $key => $value) {
            $copy[$key] = is_array($value) ? self::copyMap($value) : $value;
        }

        return $copy;
    }

    /**
     * @param  array<mixed, mixed>  $branch
     * @param  list<string>  $path
     * @return array<mixed, mixed>
     */
    private static function writePath(array $branch, array $path, mixed $value): array
    {
        if ($path === []) {
            return $branch;
        }

        $head = array_shift($path);

        $nested = is_array($branch[$head] ?? null) ? $branch[$head] : [];

        if ($path === []) {
            $branch[$head] = $value;

            return $branch;
        }

        $branch[$head] = self::writePath($nested, $path, $value);

        return $branch;
    }

    /**
     * @param  array<mixed, mixed>  $branch
     * @param  list<string>  $path
     * @return array<mixed, mixed>
     */
    private static function forgetPath(array $branch, array $path): array
    {
        if ($path === []) {
            return $branch;
        }

        $head = array_shift($path);

        if (! array_key_exists($head, $branch)) {
            return $branch;
        }

        if ($path === []) {
            unset($branch[$head]);

            return $branch;
        }

        if (! is_array($branch[$head])) {
            return $branch;
        }

        $branch[$head] = self::forgetPath($branch[$head], $path);

        return $branch;
    }
}
