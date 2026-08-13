<?php

declare(strict_types=1);

namespace Rick\Laravel\Tests\Unit\Domain;

use InvalidArgumentException;
use PHPUnit\Framework\TestCase;
use Rick\Laravel\Domain\Exception\ParcelItemAmbiguousException;
use Rick\Laravel\Domain\Exception\ParcelItemNotFoundException;
use Rick\Laravel\Domain\Interface\ParcelItemBase;
use Rick\Laravel\Domain\ValueObject\Parcel;

final class ParcelTest extends TestCase
{
    public function test_it_normalizes_lists_and_maps_by_concrete_class(): void
    {
        $first = new FirstParcelItem;
        $second = new SecondParcelItem;

        self::assertSame(
            [
                FirstParcelItem::class => $first,
                SecondParcelItem::class => $second,
            ],
            Parcel::fromArray([$first, $second])->toArray(),
        );
        self::assertSame(
            [
                FirstParcelItem::class => $first,
                SecondParcelItem::class => $second,
            ],
            Parcel::fromArray(['ignored' => $first, 99 => $second])->toArray(),
        );
    }

    public function test_it_round_trips_its_shallow_array_representation(): void
    {
        $parcel = Parcel::fromArray([new FirstParcelItem, new SecondParcelItem]);

        $restored = Parcel::fromArray($parcel->toArray());

        self::assertSame($parcel->toArray(), $restored->toArray());
    }

    public function test_put_is_immutable_and_replaces_the_same_concrete_class(): void
    {
        $originalItem = new FirstParcelItem('original');
        $replacement = new FirstParcelItem('replacement');
        $original = Parcel::fromArray([$originalItem]);

        $changed = $original->put($replacement);

        self::assertSame($originalItem, $original->get(FirstParcelItem::class));
        self::assertSame($replacement, $changed->get(FirstParcelItem::class));
        self::assertCount(1, $changed->toArray());
    }

    public function test_from_array_replaces_an_earlier_item_of_the_same_concrete_class(): void
    {
        $first = new FirstParcelItem('first');
        $last = new FirstParcelItem('last');

        $parcel = Parcel::fromArray([$first, $last]);

        self::assertSame([FirstParcelItem::class => $last], $parcel->toArray());
    }

    public function test_get_and_has_search_by_interface(): void
    {
        $item = new FirstParcelItem;
        $parcel = Parcel::fromArray([$item]);

        self::assertTrue($parcel->has(GroupedParcelItemBase::class));
        self::assertSame($item, $parcel->get(GroupedParcelItemBase::class));
    }

    public function test_delete_is_immutable_and_removes_all_matching_implementations(): void
    {
        $first = new FirstParcelItem;
        $second = new SecondParcelItem;
        $other = new OtherParcelItem;
        $original = Parcel::fromArray([$first, $second, $other]);

        $changed = $original->delete(GroupedParcelItemBase::class);

        self::assertSame(
            [
                FirstParcelItem::class => $first,
                SecondParcelItem::class => $second,
                OtherParcelItem::class => $other,
            ],
            $original->toArray(),
        );
        self::assertSame([OtherParcelItem::class => $other], $changed->toArray());
    }

    public function test_get_throws_when_an_item_is_missing(): void
    {
        $this->expectException(ParcelItemNotFoundException::class);
        $this->expectExceptionMessage(
            'Parcel item matching ['.OtherParcelItem::class.'] was not found.',
        );

        Parcel::fromArray([])->get(OtherParcelItem::class);
    }

    public function test_get_throws_when_an_interface_match_is_ambiguous(): void
    {
        $this->expectException(ParcelItemAmbiguousException::class);
        $this->expectExceptionMessage(
            'Parcel contains [2] items matching ['.GroupedParcelItemBase::class
            .']; exactly one is required.',
        );

        Parcel::fromArray([new FirstParcelItem, new SecondParcelItem])
            ->get(GroupedParcelItemBase::class);
    }

    public function test_from_array_rejects_an_invalid_item(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage(
            'Parcel items must implement ['.ParcelItemBase::class.']; [string] given.',
        );

        Parcel::fromArray([new FirstParcelItem, 'invalid']);
    }
}

interface GroupedParcelItemBase extends ParcelItemBase {}

final readonly class FirstParcelItem implements GroupedParcelItemBase
{
    public function __construct(public string $value = '') {}
}

final class SecondParcelItem implements GroupedParcelItemBase {}

final class OtherParcelItem implements ParcelItemBase {}
