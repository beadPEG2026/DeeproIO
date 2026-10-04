<?php

namespace Tests\Unit\Umi\V2;

use App\Domain\Umi\V2\StockPoints;
use DomainException;
use InvalidArgumentException;
use PHPUnit\Framework\TestCase;

final class StockPointsTest extends TestCase
{
    private function burn(string $id = 'burn-1', string $finality = 'final'): array
    {
        return ['id' => $id, 'account_id' => 'account-1', 'purpose' => 'withdrawal',
            'withdrawal_id' => '42', 'finality_status' => $finality,
            'burned_umi' => '9', 'confirmation_ref' => 'chain-tx-1'];
    }

    public function test_confirmed_burn_adds_internal_display_points_once(): void
    {
        $book = StockPoints::empty('account-1', 'umi-stock-fund');
        [$next, $grant] = $book->creditFromBurn('grant-1', $this->burn(), '1', 'rules-1');
        $this->assertSame('0', $book->total());
        $this->assertSame('9', $next->total());
        $this->assertSame('9', $grant['burned_umi']);
        $this->assertSame('9', $grant['points']);
        $this->assertSame('withdrawal', $grant['source_purpose']);
        $this->assertSame('42', $grant['source_withdrawal_id']);
        $this->assertSame('final', $grant['source_finality_status']);
        $this->assertSame('股票基金积分', $next->snapshot()['display_name']);
        $this->assertArrayNotHasKey('shares', $grant);
        [$replay, $same] = $next->creditFromBurn('grant-1', $this->burn(), '1.00', 'rules-1');
        $this->assertSame($next, $replay);
        $this->assertSame($grant, $same);
        $this->assertCount(1, $next->snapshot()['grants']);
    }

    public function test_pending_or_failed_burn_never_grants_points(): void
    {
        $book = StockPoints::empty('account-1', 'umi-stock-fund');
        $this->expectException(DomainException::class);
        $book->creditFromBurn('grant-1', $this->burn(finality: 'pending'), '1', 'rules-1');
    }

    public function test_same_burn_cannot_be_awarded_with_a_second_grant_id(): void
    {
        [$book] = StockPoints::empty('account-1', 'umi-stock-fund')->creditFromBurn('grant-1', $this->burn(), '1', 'rules-1');
        $this->expectException(DomainException::class);
        $book->creditFromBurn('grant-2', $this->burn(), '1', 'rules-1');
    }

    public function test_same_grant_id_cannot_change_rate_or_burn_source(): void
    {
        [$book] = StockPoints::empty('account-1', 'umi-stock-fund')->creditFromBurn('grant-1', $this->burn(), '1', 'rules-1');
        $this->expectException(DomainException::class);
        $book->creditFromBurn('grant-1', $this->burn(), '2', 'rules-1');
    }

    public function test_burn_cannot_grant_points_to_another_account(): void
    {
        $book = StockPoints::empty('account-2', 'umi-stock-fund');
        $this->expectException(DomainException::class);
        $book->creditFromBurn('grant-1', $this->burn(), '1', 'rules-1');
    }

    public function test_persisted_points_replay_and_reject_changed_total(): void
    {
        [$book] = StockPoints::empty('account-1', 'umi-stock-fund')->creditFromBurn('grant-1', $this->burn(), '1', 'rules-1');
        $snapshot = $book->snapshot();
        $this->assertSame($snapshot, StockPoints::restore($snapshot)->snapshot());
        $reordered = array_reverse($snapshot, true);
        $reordered['grants'][0] = array_reverse($reordered['grants'][0], true);
        $this->assertSame($snapshot, StockPoints::restore($reordered)->snapshot());
        $snapshot['points'] = '90';
        $this->expectException(DomainException::class);
        StockPoints::restore($snapshot);
    }

    public function test_activation_burn_cannot_create_stock_points(): void
    {
        $burn = $this->burn();
        $burn['purpose'] = 'activation';
        $burn['cycle_id'] = '3';
        $burn['withdrawal_id'] = null;
        $this->expectException(DomainException::class);
        $this->expectExceptionMessage('exit withdrawal burn');
        StockPoints::empty('account-1', 'umi-stock-fund')->creditFromBurn('grant-1', $burn, '1', 'rules-1');
    }

    public function test_burn_without_withdrawal_source_cannot_create_stock_points(): void
    {
        $burn = $this->burn();
        unset($burn['withdrawal_id']);
        $this->expectException(DomainException::class);
        $this->expectExceptionMessage('withdrawal source ID');
        StockPoints::empty('account-1', 'umi-stock-fund')->creditFromBurn('grant-1', $burn, '1', 'rules-1');
    }

    public function test_nonfinal_withdrawal_burn_cannot_create_stock_points(): void
    {
        $this->expectException(DomainException::class);
        $this->expectExceptionMessage('not final');
        StockPoints::empty('account-1', 'umi-stock-fund')->creditFromBurn('grant-1', $this->burn(finality: 'confirmed'), '1', 'rules-1');
    }

    public function test_program_id_over_eighty_bytes_is_rejected_before_persistence(): void
    {
        $this->expectException(InvalidArgumentException::class);
        StockPoints::empty('account-1', str_repeat('P', 81));
    }

    public function test_burn_confirmation_reference_over_one_hundred_sixty_bytes_is_rejected(): void
    {
        $burn = $this->burn();
        $burn['confirmation_ref'] = str_repeat('R', 161);
        $this->expectException(InvalidArgumentException::class);
        StockPoints::empty('account-1', 'umi-stock-fund')->creditFromBurn('grant-1', $burn, '1', 'rules-1');
    }

    public function test_reversal_is_append_only_negative_and_idempotent(): void
    {
        [$book, $grant] = StockPoints::empty('account-1', 'umi-stock-fund')
            ->creditFromBurn('grant-1', $this->burn(), '1', 'rules-1');
        [$reversed, $event] = $book->reverseGrant('reversal-1', 'grant-1', 'reorg-1', '原销毁最终性被撤销');
        $this->assertSame('-9', $event['delta_points']);
        $this->assertSame('0', $reversed->total());
        $this->assertSame('9', $grant['points']);
        $this->assertCount(1, $reversed->snapshot()['grants']);
        $this->assertCount(1, $reversed->snapshot()['reversals']);
        $this->assertCount(2, $reversed->snapshot()['entries']);
        [$replay, $same] = $reversed->reverseGrant('reversal-1', 'grant-1', 'reorg-1', '原销毁最终性被撤销');
        $this->assertSame($reversed, $replay);
        $this->assertSame($event, $same);
        [$creditReplay] = $reversed->creditFromBurn('grant-1', $this->burn(), '1', 'rules-1');
        $this->assertSame($reversed, $creditReplay);
        $this->assertSame('0', $creditReplay->total());
    }

    public function test_same_grant_cannot_be_reversed_twice_with_different_ids(): void
    {
        [$book] = StockPoints::empty('account-1', 'umi-stock-fund')
            ->creditFromBurn('grant-1', $this->burn(), '1', 'rules-1');
        [$book] = $book->reverseGrant('reversal-1', 'grant-1', 'reorg-1', '原销毁最终性被撤销');
        $this->expectException(DomainException::class);
        $this->expectExceptionMessage('already reversed');
        $book->reverseGrant('reversal-2', 'grant-1', 'reorg-2', '再次冲正');
    }

    public function test_reversal_replay_preserves_interleaved_event_order(): void
    {
        [$book] = StockPoints::empty('account-1', 'umi-stock-fund')
            ->creditFromBurn('grant-1', $this->burn(), '1', 'rules-1');
        [$book] = $book->reverseGrant('reversal-1', 'grant-1', 'reorg-1', '原销毁最终性被撤销');
        $secondBurn = $this->burn(id: 'burn-2');
        $secondBurn['withdrawal_id'] = '43';
        $secondBurn['burned_umi'] = '4';
        $secondBurn['confirmation_ref'] = 'chain-tx-2';
        [$book] = $book->creditFromBurn('grant-2', $secondBurn, '1', 'rules-1');
        $this->assertSame('4', $book->total());
        $this->assertSame($book->snapshot(), StockPoints::restore($book->snapshot())->snapshot());
    }
}
