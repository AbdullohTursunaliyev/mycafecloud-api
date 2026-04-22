<?php

namespace App\Enums;

enum PaymentMethod: string
{
    case Cash = 'cash';
    case Card = 'card';
    case Balance = 'balance';

    public static function fromNullable(?string $value): ?self
    {
        if ($value === null) {
            return null;
        }

        $normalized = strtolower(trim($value));
        if ($normalized === '') {
            return null;
        }

        foreach (self::cases() as $case) {
            if ($normalized === $case->value) {
                return $case;
            }
        }

        if (str_contains($normalized, self::Card->value)) {
            return self::Card;
        }

        if (str_contains($normalized, self::Cash->value)) {
            return self::Cash;
        }

        if (str_contains($normalized, self::Balance->value)) {
            return self::Balance;
        }

        return null;
    }

    public static function values(): array
    {
        return array_map(
            static fn(self $case) => $case->value,
            self::cases(),
        );
    }

    public static function promotionValues(): array
    {
        return [self::Cash->value];
    }

    public function isCash(): bool
    {
        return $this === self::Cash;
    }

    public function isCard(): bool
    {
        return $this === self::Card;
    }

    public function isBalance(): bool
    {
        return $this === self::Balance;
    }
}
