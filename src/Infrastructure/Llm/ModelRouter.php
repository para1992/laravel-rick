<?php

declare(strict_types=1);

namespace Rick\Laravel\Infrastructure\Llm;

use Rick\Laravel\Application\Execution\Support\Llm\Interface\ProviderRouteResolverBase;

final readonly class ModelRouter implements ProviderRouteResolverBase
{
    /** @param array<string, array{provider?: string|array<string, string|null>|null, model?: string|null}> $routes */
    public function __construct(private array $routes) {}

    /** @return array{provider: string|array<string, string|null>|null, model: string|null} */
    public function route(string $policy): array
    {
        $route = $this->routes[$policy] ?? $this->routes['medium'] ?? $this->routes['default'] ?? [];

        return [
            'provider' => $route['provider'] ?? null,
            'model' => $route['model'] ?? null,
        ];
    }

    public function identity(string $modelTier): string
    {
        $route = $this->route($modelTier);
        $provider = $route['provider'];
        if (is_array($provider)) {
            ksort($provider);
            $provider = json_encode($provider, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES);
        }

        return hash('sha256', implode("\0", [
            is_string($provider) ? $provider : '',
            $route['model'] ?? '',
        ]));
    }
}
