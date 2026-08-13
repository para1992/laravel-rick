<?php

declare(strict_types=1);

namespace Rick\Laravel\Domain\Workflow\ValueObject;

final readonly class DefinitionOfDone
{
    /** @param string|array<string, mixed> $value */
    private function __construct(
        private string|array $value,
        private bool $automatic,
    ) {}

    public static function fromString(string $value): self
    {
        $normalized = trim($value);

        if ($normalized === '' || strtolower($normalized) === 'auto' || $normalized === '__auto_dod__') {
            return self::automatic();
        }

        return new self($normalized, false);
    }

    /** @param array<string, mixed> $value */
    public static function structured(array $value): self
    {
        return new self($value, false);
    }

    public static function automatic(): self
    {
        return new self('auto', true);
    }

    public function isAutomatic(): bool
    {
        return $this->automatic;
    }

    /** @return string|array<string, mixed> */
    public function value(): string|array
    {
        return $this->value;
    }

    public function toPromptString(): string
    {
        return is_array($this->value)
            ? json_encode($this->value, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR)
            : $this->value;
    }
}
