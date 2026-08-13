<?php

declare(strict_types=1);

namespace Rick\Laravel\Domain\ValueObject;

use InvalidArgumentException;
use Rick\Laravel\Domain\Exception\ParcelItemAmbiguousException;
use Rick\Laravel\Domain\Exception\ParcelItemNotFoundException;
use Rick\Laravel\Domain\Interface\ParcelItemBase;

final readonly class Parcel
{
    /** @param array<class-string<ParcelItemBase>, ParcelItemBase> $items */
    private function __construct(
        private array $items,
    ) {}

    /**
     * @param  array<array-key, mixed>  $items
     */
    public static function fromArray(array $items): self
    {
        $normalized = [];

        foreach ($items as $item) {
            if (! $item instanceof ParcelItemBase) {
                throw new InvalidArgumentException(sprintf(
                    'Parcel items must implement [%s]; [%s] given.',
                    ParcelItemBase::class,
                    get_debug_type($item),
                ));
            }

            $normalized[$item::class] = $item;
        }

        return new self($normalized);
    }

    /**
     * @return array<class-string<ParcelItemBase>, ParcelItemBase>
     */
    public function toArray(): array
    {
        return $this->items;
    }

    public function put(ParcelItemBase $item): self
    {
        $items = $this->items;
        $items[$item::class] = $item;

        return new self($items);
    }

    /**
     * @template T of ParcelItemBase
     *
     * @param  class-string<T>  $type
     * @return T
     */
    public function get(string $type): ParcelItemBase
    {
        $matches = $this->matching($type);
        $count = count($matches);

        if ($count === 0) {
            throw ParcelItemNotFoundException::for($type);
        }

        if ($count > 1) {
            throw ParcelItemAmbiguousException::for($type, $count);
        }

        /** @var T $item */
        $item = reset($matches);

        return $item;
    }

    /** @param class-string<ParcelItemBase> $type */
    public function has(string $type): bool
    {
        return $this->matching($type) !== [];
    }

    /** @param class-string<ParcelItemBase> $type */
    public function delete(string $type): self
    {
        $items = array_filter(
            $this->items,
            static fn (ParcelItemBase $item): bool => ! $item instanceof $type,
        );

        return new self($items);
    }

    /**
     * @param  class-string<ParcelItemBase>  $type
     * @return array<class-string<ParcelItemBase>, ParcelItemBase>
     */
    private function matching(string $type): array
    {
        return array_filter(
            $this->items,
            static fn (ParcelItemBase $item): bool => $item instanceof $type,
        );
    }
}
