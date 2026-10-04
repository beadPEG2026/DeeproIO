<?php

namespace Tests\Unit\Umi\V2;

use App\Domain\Umi\V2\Cycle;
use App\Domain\Umi\V2\Decimal;
use App\Domain\Umi\V2\StockPoints;
use InvalidArgumentException;
use OverflowException;
use PHPUnit\Framework\TestCase;

final class DecimalTest extends TestCase
{
    public function test_exactly_thirty_integer_digits_fit_decimal_54_24(): void
    {
        $this->assertSame(
            '999999999999998000000000000001',
            Decimal::mul('999999999999999', '999999999999999'),
        );
        $this->assertSame('999999999999999999999999999999', Decimal::add('999999999999999999999999999998', '1'));
    }

    public function test_multiplication_over_thirty_integer_digits_is_rejected_before_persistence(): void
    {
        $this->expectException(OverflowException::class);
        $this->expectExceptionMessage('DECIMAL(54,24)');
        Decimal::mul('999999999999999999', '999999999999999999');
    }

    public function test_aggregate_overflow_is_rejected_before_persistence(): void
    {
        $this->expectException(OverflowException::class);
        Decimal::add('999999999999999999999999999999', '1');
    }

    public function test_cycle_cannot_open_when_price_times_principal_exceeds_db_precision(): void
    {
        $this->expectException(OverflowException::class);
        Cycle::open('cycle-1', 'account-1', '999999999999999999', '999999999999999999', 'rules-1');
    }

    public function test_internal_points_cannot_exceed_db_precision(): void
    {
        $book = StockPoints::empty('account-1', 'fund');
        $this->expectException(OverflowException::class);
        $book->creditFromBurn('grant-1', [
            'id' => 'burn-1', 'account_id' => 'account-1', 'purpose' => 'withdrawal',
            'withdrawal_id' => '42', 'finality_status' => 'final',
            'burned_umi' => '999999999999999999', 'confirmation_ref' => 'tx-1',
        ], '999999999999999999', 'rules-1');
    }

    public function test_binary_float_amount_is_rejected_even_from_a_weakly_typed_caller(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('must be a string');
        Cycle::open('cycle-1', 'account-1', 100.0, '1', 'rules-1');
    }

    public function test_binary_float_calculation_is_rejected(): void
    {
        $this->expectException(InvalidArgumentException::class);
        Decimal::mul(0.1, '100');
    }
}
