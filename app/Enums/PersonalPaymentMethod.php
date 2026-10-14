<?php

namespace App\Enums;

/**
 * How the owner paid for something personal.
 *
 * Its own short list rather than {@see PaymentMode}, because that enum decides
 * which ledger account money leaves — and this money leaves no account the
 * workshop keeps. A card is the commonest way to pay for groceries and means
 * nothing to the books, which is the whole difference between the two lists.
 */
enum PersonalPaymentMethod: string
{
    case Cash = 'cash';
    case Upi = 'upi';
    case Card = 'card';
    case Bank = 'bank';

    public function label(): string
    {
        return match ($this) {
            self::Cash => 'Cash',
            self::Upi => 'UPI',
            self::Card => 'Card',
            self::Bank => 'Bank transfer',
        };
    }

    /**
     * @return array<int, string>
     */
    public static function values(): array
    {
        return array_column(self::cases(), 'value');
    }
}
