<?php

declare(strict_types=1);

namespace Rick\Laravel\Infrastructure\Configuration;

final readonly class TenantConfiguration
{
    /** @param list<string>|null $catalog */
    public function __construct(
        public string $default,
        public ?array $catalog,
    ) {}

    /** @param array<string, mixed> $input */
    public static function from(array $input): self
    {
        ConfigurationInput::keys($input, ['default', 'catalog'], 'tenant');
        $catalog = $input['catalog'] ?? null;
        if ($catalog !== null) {
            $values = [];
            foreach (ConfigurationInput::list($catalog, 'tenant.catalog') as $index => $tenant) {
                $values[] = ConfigurationInput::identifier($tenant, "tenant.catalog.{$index}");
            }
            $catalog = array_values(array_unique($values));
        }

        return new self(
            ConfigurationInput::identifier($input['default'] ?? null, 'tenant.default'),
            $catalog,
        );
    }
}
