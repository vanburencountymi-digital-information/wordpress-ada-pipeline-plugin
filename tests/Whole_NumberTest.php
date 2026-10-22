<?php

namespace AdaRemediationClient\Tests;

use AdaRemediationClient\Whole_Number;
use PHPUnit\Framework\TestCase;

class Whole_NumberTest extends TestCase
{
    /**
     * @dataProvider values
     * @param mixed $value
     */
    public function test_only_an_int_or_a_string_of_digits_is_a_whole_number($value, ?int $expected): void
    {
        $this->assertSame($expected, Whole_Number::from($value));
    }

    public function values(): array
    {
        return [
            'an int' => [120, 120],
            'zero' => [0, 0],
            'a string of digits' => ['120', 120],
            'zero as text' => ['0', 0],
            'leading zeros' => ['007', 7],
            'only zeros' => ['000', 0],
            'leading zeros then too many digits' => ['0099999999999999999999', null],
            'the biggest int' => [PHP_INT_MAX, PHP_INT_MAX],
            'digits too many for an int' => ['99999999999999999999', null],
            'a negative int' => [-5, null],
            'a negative string' => ['-5', null],
            'a plus sign' => ['+5', null],
            'a fraction' => ['1.5', null],
            'a float' => [90.5, null],
            'a float that is whole' => [5.0, null],
            'an HTTP date' => ['Wed, 21 Oct 2026 07:28:00 GMT', null],
            'spaces around' => [' 5 ', null],
            'an empty string' => ['', null],
            'true' => [true, null],
            'false' => [false, null],
            'null' => [null, null],
            'an array' => [[5], null],
        ];
    }
}
