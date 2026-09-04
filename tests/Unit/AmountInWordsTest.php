<?php

namespace Tests\Unit;

use App\Support\AmountInWords;
use App\Support\Money;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * The amount in words, as it appears on the invoice.
 *
 * The figure a digit cannot be added to, which is the only reason it is on the
 * document at all — so the interesting cases here are the ones that quietly
 * produce a *plausible* wrong answer: the wrong grouping system, a swallowed
 * paisa, a singular where a plural belongs.
 *
 * @see \App\Support\AmountInWords
 */
class AmountInWordsTest extends TestCase
{
    #[Test]
    #[DataProvider('amounts')]
    public function it_writes_the_amount_the_way_an_indian_invoice_does(string $amount, string $expected): void
    {
        $this->assertSame($expected, AmountInWords::rupees(Money::of($amount)));
    }

    /**
     * @return array<string, array{0: string, 1: string}>
     */
    public static function amounts(): array
    {
        return [
            'nothing at all' => ['0.00', 'Zero Rupees Only'],

            // Singular, both sides. "One Rupees" is the sort of thing that ends
            // up photographed and sent back to the workshop.
            'one rupee' => ['1.00', 'One Rupee Only'],
            'one paisa' => ['0.01', 'Zero Rupees and One Paisa Only'],

            'paise are stated when there are any' => ['10.50', 'Ten Rupees and Fifty Paise Only'],

            // And are not stated when there are none. A workshop that rounds to
            // the rupee would otherwise read "and Zero Paise" on every invoice
            // it ever issues.
            'and not when there are none' => ['6701.00', 'Six Thousand Seven Hundred One Rupees Only'],

            'hyphenated the way english writes them' => ['42.00', 'Forty-Two Rupees Only'],
            'the teens are not tens and units' => ['19.00', 'Nineteen Rupees Only'],
            'a round ten needs no hyphen' => ['40.00', 'Forty Rupees Only'],

            /*
            | Indian grouping, which is the whole reason this is not a library
            | call. 1,50,000 is One Lakh Fifty Thousand — "One Hundred Fifty
            | Thousand" is not a translation of it, it is a different number
            | system, and on a document an assessing officer may read the local
            | convention is the correct one.
            */
            'a lakh, not a hundred thousand' => ['150000.00', 'One Lakh Fifty Thousand Rupees Only'],
            'ten lakh, not a million' => ['1000000.00', 'Ten Lakh Rupees Only'],
            'a crore, not ten million' => [
                '12345678.90',
                'One Crore Twenty-Three Lakh Forty-Five Thousand Six Hundred Seventy-Eight Rupees and Ninety Paise Only',
            ],

            // Past a crore the count of crores is itself a full number, which is
            // what the recursion is for.
            'crores counted in lakhs' => [
                '1234567890.00',
                'One Hundred Twenty-Three Crore Forty-Five Lakh Sixty-Seven Thousand Eight Hundred Ninety Rupees Only',
            ],

            'a plain hundred' => ['100.00', 'One Hundred Rupees Only'],

            // Reached only by a caller holding something genuinely below zero.
            // Printing the magnitude silently would be the worst answer
            // available.
            'below zero says so' => ['-500.25', 'Minus Five Hundred Rupees and Twenty-Five Paise Only'],
        ];
    }

    /**
     * The paise are the two digits of the minor unit, not a decimal fraction
     * that has been re-derived — which is what stops ninety-nine paise printing
     * as ninety-eight for want of a rounding rule.
     */
    #[Test]
    public function every_paisa_from_one_to_ninety_nine_is_written_exactly(): void
    {
        for ($paise = 1; $paise <= 99; $paise++) {
            $words = AmountInWords::rupees(Money::fromMinor(100 + $paise));

            $this->assertStringStartsWith('One Rupee and ', $words);
            $this->assertStringEndsWith($paise === 1 ? ' Paisa Only' : ' Paise Only', $words);
        }
    }
}
