<?php

namespace Tests\Feature\Deepro;

use App\Services\Custody\CustodyBridge;
use App\Services\Custody\CustodyService;
use App\Services\Umi\V2\BurnProof;
use App\Services\Umi\V2\CycleStore;
use App\Domain\Umi\V2\Decimal;
use App\Domain\Umi\V2\Cycle;
use App\Services\Umi\V2\FundedBurn;
use App\Services\Umi\V2\FundedConfiguration;
use App\Services\Umi\V2\FundedIntake;
use App\Services\Umi\V2\FundedReadiness;
use App\Services\Umi\V2\FundedSettlement;
use App\Services\Umi\V2\FundedWallet;
use App\Services\Umi\V2\FundedWithdrawal;
use App\Services\Umi\V2\MemberEnrollment;
use App\Services\Umi\V2\StockShares;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

/** Isolated SQLite/PostgreSQL accounting; every chain receipt is an explicit fixture. */
final class UmiV2FundedFlowTest extends TestCase
{
    private FundedIntake $intake;
    private FundedSettlement $settlement;
    private FundedWithdrawal $withdrawal;
    private FundedBurn $burn;

    public function createApplication()
    {
        $app = require __DIR__ . '/../../../bootstrap/app.php';
        $app->make(Kernel::class)->bootstrap();
        return $app;
    }

    protected function setUp(): void
    {
        parent::setUp();
        config(['app.key'=>'base64:'.base64_encode(str_repeat('t',32)),'database.connections.umi_funded_test' => [
            'driver' => 'sqlite', 'database' => ':memory:', 'prefix' => '',
            'foreign_key_constraints' => true,
        ], 'database.default' => 'umi_funded_test',
            'umi-v2.funded_enabled' => true, 'umi-v2.timezone' => 'Asia/Shanghai',
            'umi.asset.contract' => '0xa1bc94946fc3479fe5602be67c7b554335423a4a',
            'hk-price-products.trading_enabled' => true,
            'hk-price-products.assets.HK08379.tradingEnabled' => true]);
        if (getenv('UMI_FUNDED_PG_TEST') === '1') {
            $database = (string) getenv('UMI_FUNDED_PG_DATABASE');
            if (!app()->environment('testing') || !str_starts_with($database, 'umi_integration_test_')
                || getenv('UMI_FUNDED_PG_HOST') !== '127.0.0.1') {
                throw new \RuntimeException('Refusing to reset any non-isolated PostgreSQL database');
            }
            config(['app.key'=>'base64:'.base64_encode(str_repeat('t',32)),'database.connections.umi_funded_test' => ['driver' => 'pgsql',
                'host' => getenv('UMI_FUNDED_PG_HOST'), 'port' => getenv('UMI_FUNDED_PG_PORT'),
                'database' => $database, 'username' => getenv('UMI_FUNDED_PG_USER'),
                'password' => getenv('UMI_FUNDED_PG_PASSWORD'), 'charset' => 'utf8',
                'prefix' => '', 'schema' => 'public', 'sslmode' => 'prefer']]);
        }
        DB::purge('umi_funded_test');
        if (DB::getDriverName() === 'pgsql') {
            DB::statement('DROP SCHEMA public CASCADE');
            DB::statement('CREATE SCHEMA public');
        }
        Schema::create('users', static function (Blueprint $t): void {
            $t->id(); $t->boolean('is_xn')->default(false);
            $t->boolean('is_xm')->default(false);
            $t->boolean('deleted')->default(false);
            $t->boolean('deactivated')->default(false);
        });
        Schema::create('currencies', static function (Blueprint $t): void {
            $t->id(); $t->string('symbol'); $t->string('bep_contract');
            $t->string('contract')->nullable(); $t->unsignedInteger('decimals')->default(8);
            $t->boolean('status')->default(true); $t->boolean('deposit_status')->default(false);
            $t->boolean('withdraw_status')->default(false); $t->string('asset_category')->nullable();
            $t->string('asset_unit')->nullable(); $t->text('asset_reference')->nullable();
            $t->softDeletes();
        });
        Schema::create('markets', static function (Blueprint $t): void {
            $t->id(); $t->string('name'); $t->unsignedBigInteger('base_currency_id');
            $t->unsignedBigInteger('quote_currency_id'); $t->boolean('status')->default(true);
            $t->boolean('trade_status')->default(true); $t->unsignedInteger('base_precision')->default(8); $t->softDeletes();
        });
        Schema::create('networks', static function (Blueprint $t): void {
            $t->id(); $t->string('slug'); $t->boolean('status')->default(true);
        });
        Schema::create('currency_networks', static function (Blueprint $t): void {
            $t->unsignedBigInteger('currency_id'); $t->unsignedBigInteger('network_id');
        });
        Schema::create('order_histories', static function (Blueprint $t): void {
            $t->string('id')->primary(); $t->unsignedBigInteger('market_id'); $t->unsignedBigInteger('user_id');
            $t->string('settlement_domain');
        });
        Schema::create('transactions', static function (Blueprint $t): void {
            $t->id(); $t->string('order_id'); $t->unsignedBigInteger('market_id'); $t->unsignedBigInteger('user_id');
            $t->boolean('is_maker')->default(false); $t->integer('is_volume')->default(0);
            $t->decimal('price',36,18); $t->decimal('base_currency',36,18); $t->dateTime('created_at');
        });
        Schema::create('wallets', static function (Blueprint $t): void {
            $t->id(); $t->unsignedBigInteger('user_id'); $t->unsignedBigInteger('currency_id');
            $t->decimal('balance_in_wallet', 36, 18)->default(0);
            $t->decimal('balance_in_trade', 36, 18)->default(0); $t->decimal('balance_in_order',36,18)->default(0); $t->timestamps();
        });
        Schema::create('custody_transfers', static function (Blueprint $t): void {
            $t->id(); $t->string('purpose'); $t->string('status');
            $t->string('txn'); $t->string('sender'); $t->string('destination');
            $t->string('contract'); $t->decimal('amount', 36, 18);
            $t->unsignedInteger('confirmations')->default(15);
        });
        Schema::create('deposits', static function (Blueprint $t): void {
            $t->id(); $t->unsignedBigInteger('user_id');
            $t->unsignedBigInteger('currency_id'); $t->unsignedBigInteger('network_id');
            $t->string('source_id'); $t->string('status');
            $t->decimal('amount', 36, 18); $t->timestamps();
        });
        Schema::create('deposit_review_events',static function(Blueprint $t):void {$t->id();$t->unsignedBigInteger('deposit_id');$t->string('reason');$t->string('status');});
        Schema::create('chain_deposit_receipts', static function (Blueprint $t): void {
            $t->unsignedBigInteger('deposit_id')->primary();
            $t->decimal('credited_amount', 36, 18);
            $t->text('evidence'); $t->dateTime('created_at');
        });
        (require base_path('database/migrations/deepro/2026_09_30_210000_umi_v2_schema.php'))->up();
        (require base_path('database/migrations/deepro/2026_10_01_040000_umi_v2_funded_workflow.php'))->up();
        (require base_path('database/migrations/deepro/2026_10_01_050000_umi_member_stock_lifecycle.php'))->up();
        (require base_path('database/migrations/deepro/2026_10_01_060000_umi_stock_trading_wallet_transfer.php'))->up();
        (require base_path('database/migrations/deepro/2026_10_01_070000_umi_stock_audit_protection.php'))->up();
        (require base_path('database/migrations/deepro/2026_10_01_080000_umi_fixed_day_stock_lifecycle.php'))->up();
        (require base_path('database/migrations/deepro/2026_10_01_090000_umi_account_cutover.php'))->up();
        (require base_path('database/migrations/deepro/2026_10_02_080000_umi_capture_snapshots.php'))->up();
        (require base_path('database/migrations/deepro/2026_10_02_090000_umi_spot_funding.php'))->up();
        (require base_path('database/migrations/deepro/2026_10_02_220000_umi_operational_recovery.php'))->up();
        DB::table('users')->insert([['id' => 1], ['id' => 2], ['id' => 3]]);
        DB::table('currencies')->insert(['id' => 1, 'symbol' => 'UMI',
            'bep_contract' => config('umi.asset.contract')]);
        DB::table('currencies')->insert(['id' => 2, 'symbol' => 'USDT', 'bep_contract' => '']);
        DB::table('currencies')->insert(['id' => 3, 'symbol' => 'HK08379', 'bep_contract' => '',
            'asset_category' => 'stock', 'asset_unit' => 'product_unit',
            'asset_reference' => json_encode(['instrumentType' => 'equity_price_reference',
                'securityCode' => '08379'])]);
        DB::table('markets')->insert(['name' => 'HK08379-USDT', 'base_currency_id' => 3,
            'quote_currency_id' => 2, 'status' => true, 'trade_status' => true,
            'base_precision' => 8]);
        DB::table('networks')->insert(['id'=>6,'slug'=>'bep20']);
        DB::table('currency_networks')->insert(['currency_id'=>1,'network_id'=>6]);
        DB::table('markets')->insert(['id'=>2,'name'=>'UMI-USDT','base_currency_id'=>1,'quote_currency_id'=>2]);
        $this->spotFill('initial', '1', now());
        // Explicit executable ask fixture; independent of the legacy trade fixtures below.
        app()->instance(\App\Services\Umi\V2\BestAskBook::class, new class extends \App\Services\Umi\V2\BestAskBook {
            public function read(\App\Models\Market\Market $market): array {
                return ['asks'=>[['price'=>'1','quantity'=>'100000']], 'bids'=>[['price'=>'0.99','quantity'=>'100000']]];
            }
        });
        app(\App\Services\Umi\V2\BestAskQuote::class)->read(true);
        foreach ([1 => '10000', 2 => '110', 3 => '100'] as $user => $balance) {
            DB::table('wallets')->insert(['user_id' => $user, 'currency_id' => 1,
                'balance_in_wallet' => $user===1?$balance:'0', 'balance_in_trade' => $user===1?'0':$balance, 'created_at' => \App\Services\Umi\V2\FundedTime::database(now()), 'updated_at' => \App\Services\Umi\V2\FundedTime::database(now())]);
        }
        foreach ([1 => '5', 2 => '0', 3 => '0'] as $user => $balance) {
            DB::table('wallets')->insert(['user_id' => $user, 'currency_id' => 3,
                'balance_in_trade' => $balance, 'created_at' => \App\Services\Umi\V2\FundedTime::database(now()), 'updated_at' => \App\Services\Umi\V2\FundedTime::database(now())]);
        }
        DB::table('umi_v2_live_settings')->where('id', 1)->update([
            'pool_user_id' => 1, 'umi_network_id' => 6,
            'dedicated_address' => '0x1111111111111111111111111111111111111111',
            'intake_enabled' => true, 'settlement_enabled' => true,
            'withdrawal_enabled' => true,
            'stock_transfer_enabled' => true,
        ]);
        foreach (['UMI_USDT' => '1', 'HK08379_USDT' => '0.1'] as $asset => $price) {
            DB::table('umi_v2_live_quotes')->insert([
                'asset' => $asset, 'price' => $price, 'source' => 'fixture',
                'source_ref' => $asset, 'observed_at' => \App\Services\Umi\V2\FundedTime::database(now()),
                'approved_by' => 1, 'created_at' => \App\Services\Umi\V2\FundedTime::database(now()),
            ]);
        }
        $wallet = new FundedWallet();
        $gate = new class($wallet) extends FundedReadiness {
            public function require(string $operation): object
            {
                return DB::table('umi_v2_live_settings')->find(1);
            }
            public function report(?object $candidate = null): array
            {
                return ['ready' => true, 'issues' => []];
            }
        };
        app()->instance(FundedReadiness::class, $gate);
        $configuration = new FundedConfiguration();
        $cycles = new CycleStore();
        $this->intake = new FundedIntake($gate, $configuration, $wallet);
        $this->settlement = new FundedSettlement($gate, $wallet, $this->intake, $cycles);
        $this->withdrawal = new FundedWithdrawal($gate, $wallet,
            $this->intake, $this->settlement, $cycles);
        $proof = new class extends BurnProof {
            public function __construct() {}
            public function verify(string $hash, string $sender, string $contract,
                string $amount, int $confirmations): array
            {
                return ['log_index' => 0, 'amount_umi' => $amount,
                    'chain_id' => 56, 'token_contract' => strtolower($contract),
                    'tx_hash' => strtolower($hash), 'block_number' => 100,
                    'block_hash' => '0x' . str_repeat('b', 64),
                    'finality_status' => 'final', 'finalized_at' => \App\Services\Umi\V2\FundedTime::database(now()),
                    'receipt_sha256' => str_repeat('a', 64)];
            }
        };
        $this->burn = new FundedBurn($gate, $wallet, $configuration,
            new CustodyService(new CustodyBridge()), $proof, $this->settlement);
    }

    public function test_wallet_cycles_referral_settlement_withdrawal_and_confirmed_burn(): void
    {
        $a = $this->rootMember(2, 'enroll-a');
        $b = $this->intake->enroll(3, $a->member_code, 'enroll-b');
        $aIntent = $this->intake->create(2, 'spot', '100', 'join-a');
        $bIntent = $this->intake->create(3, 'spot', '100', 'join-b');
        self::assertSame('pending_burn', $aIntent->status);
        self::assertSame('10200', Decimal::display((string) DB::table('wallets')
            ->where('user_id', 1)->where('currency_id', 1)->value('balance_in_wallet')));
        self::assertSame(2, DB::table('umi_v2_live_burn_lots')->count());
        self::assertSame('0', (string) DB::table('umi_v2_live_burn_proofs')->count());
        $this->finalizeLot((int) DB::table('umi_v2_live_burn_lots')->where('cycle_id', $aIntent->cycle_id)->value('id'));
        $this->finalizeLot((int) DB::table('umi_v2_live_burn_lots')->where('cycle_id', $bIntent->cycle_id)->value('id'));
        self::assertSame('active', DB::table('umi_v2_cycles')->find($aIntent->cycle_id)->status);
        self::assertSame('10', Decimal::display((string) DB::table('umi_v2_income_accounts')
            ->where('member_id', $a->id)->value('referral_pending')));
        $day = now('Asia/Shanghai')->toDateString();
        $run = $this->settleCompleteDay($day, '0.01', 1);
        self::assertFalse($run['replayed']);
        self::assertSame('310', Decimal::display((string) DB::table('umi_v2_cycles')
            ->where('id', $aIntent->cycle_id)->value('cap_umi')));
        self::assertSame('10', Decimal::display((string) DB::table('umi_v2_income_accounts')
            ->where('member_id', $a->id)->value('referral_pending')));
        self::assertTrue($this->settleCompleteDay($day, '0.01', 1)['replayed']);
        $this->withdrawal->transfer(2, (int) $aIntent->cycle_id,
            'referral', '10', 'transfer-a');
        $preview = $this->withdrawal->preview(2, '10');
        self::assertSame('10', Decimal::display($preview['income_before_umi']));
        self::assertSame('0', Decimal::display($preview['income_after_umi']));
        self::assertSame('10', Decimal::display($preview['wallet_before_umi']));
        self::assertSame('7', Decimal::display($preview['wallet_after_funding_umi']));
        $paid = $this->withdrawal->request(2, '10', 'withdraw-a');
        self::assertSame('paid', $paid->status);
        self::assertSame('10', Decimal::display((string) $paid->paid_umi));
        self::assertSame('10', Decimal::display((string) DB::table('wallets')
            ->where('user_id', 2)->where('currency_id', 1)->value('balance_in_wallet')));
        self::assertSame(0, Decimal::cmp('3', (string) $paid->required_burn_umi));
        $dashboard = app(\App\Services\Umi\V2\FundedDashboard::class)->member(2);
        self::assertCount(1, $dashboard['withdrawals'][0]->burn_records);
        self::assertCount(0, $dashboard['withdrawals'][0]->point_records);
        self::assertCount(0, app(\App\Services\Umi\V2\FundedDashboard::class)->member(3)['withdrawals']);
        $center = app(\App\Services\Umi\V2\RecordCenter::class);
        self::assertSame(1, $center->read(['dataset'=>'lots','withdrawal_id'=>$paid->id],2)['total']);
        self::assertSame(0, $center->read(['dataset'=>'lots','withdrawal_id'=>$paid->id],3)['total']);

        $this->finalizeLot((int) DB::table('umi_v2_live_burn_lots')
            ->where('withdrawal_id', $paid->id)->value('id'));
        self::assertSame('3', Decimal::display((string) DB::table('umi_v2_stock_point_entries')
            ->where('member_id', $a->id)->value('delta_points')));
        self::assertSame(1, DB::table('umi_v2_live_point_terms')->count());
        $timeline = app(\App\Services\Umi\V2\FundedDashboard::class)->member(2)['withdrawals'][0];
        self::assertSame('confirmed', $timeline->burn_records[0]->status);
        self::assertNotNull($timeline->burn_records[0]->tx_hash);
        self::assertCount(1, $timeline->point_records);
        self::assertSame('points_only', $timeline->point_records[0]->term_status);

        self::assertTrue($this->burn->creditPointsForLot((int) DB::table('umi_v2_live_burn_lots')
            ->where('withdrawal_id', $paid->id)->value('id')));
        self::assertSame(1, DB::table('umi_v2_stock_point_entries')->count());
        self::assertSame(3, DB::table('umi_v2_live_burn_proofs')->count());
        self::assertSame(3, DB::table('umi_v2_burn_evidence')->count());
        self::assertSame('203', Decimal::display((string) DB::table('umi_v2_live_burn_lots')
            ->where('status', 'confirmed')->sum('amount_umi')));
        $shares = app(StockShares::class);
        self::assertSame(['confirmed' => 0, 'unlocked' => 0], $shares->settleDue());
        $this->travelTo(\Carbon\CarbonImmutable::parse(DB::table('umi_v2_live_point_terms')->value('eligible_at')));
        self::assertSame(['confirmed' => 1, 'unlocked' => 0], $shares->settleDue());
        self::assertSame('locked', DB::table('umi_v2_live_point_terms')->value('status'));
        try {
            $shares->transfer((int) $a->id, (int) $b->id, '1', 'early-transfer', 1,
                '锁定期内不能进行站内划转');
            self::fail('Locked shares should not transfer');
        } catch (\DomainException $e) {
            self::assertSame('可划转股票份额不足。', $e->getMessage());
        }
        $this->travelTo(\Carbon\CarbonImmutable::parse(DB::table('umi_v2_live_point_terms')->value('unlock_at')));
        self::assertSame(['confirmed' => 0, 'unlocked' => 1], $shares->settleDue());
        self::assertSame('tradable', DB::table('umi_v2_live_point_terms')->value('status'));
        $move = $shares->transfer((int) $a->id, (int) $b->id, '1', 'share-transfer', 1,
            '已核对成员身份及份额来源');
        self::assertSame($move->id, $shares->transfer((int) $a->id, (int) $b->id,
            '1', 'share-transfer', 1, '已核对成员身份及份额来源')->id);
        self::assertSame('2', Decimal::display((string) DB::table('umi_v2_stock_share_accounts')
            ->where('member_id', $a->id)->value('available_shares')));
        $shares->writeOff((int) $b->id, '0.5', 'share-writeoff', 1, '管理员核对后按申请进行核销');
        self::assertSame('0.5', Decimal::display((string) DB::table('umi_v2_stock_share_accounts')
            ->where('member_id', $b->id)->value('available_shares')));
        self::assertSame(['ok' => true, 'issued_shares' => '3',
            'written_off_shares' => '0.5', 'exported_shares' => '0',
            'held_shares' => '2.5'], $shares->audit());
        $exchange = $shares->toTradingWallet(2, '1', 'stock-to-deepro');
        self::assertSame($exchange->id, $shares->toTradingWallet(2, '1', 'stock-to-deepro')->id);
        self::assertSame('4', Decimal::display((string) DB::table('wallets')
            ->where('currency_id', 3)->where('user_id', 1)->value('balance_in_trade')));
        self::assertSame('1', Decimal::display((string) DB::table('wallets')
            ->where('currency_id', 3)->where('user_id', 2)->value('balance_in_trade')));
        self::assertSame('1', Decimal::display((string) DB::table('umi_v2_stock_share_accounts')
            ->where('member_id', $a->id)->value('available_shares')));
        self::assertSame(['ok' => true, 'issued_shares' => '3',
            'written_off_shares' => '0.5', 'exported_shares' => '1',
            'held_shares' => '1.5'], $shares->audit());
    }

    public function test_stock_export_requires_switch_market_and_pool_inventory(): void
    {
        $member = $this->rootMember(2, 'stock-root');
        DB::table('umi_v2_stock_share_accounts')->insert([
            'member_id' => $member->id, 'locked_shares' => '0', 'available_shares' => '2',
            'created_at' => \App\Services\Umi\V2\FundedTime::database(now()), 'updated_at' => \App\Services\Umi\V2\FundedTime::database(now()),
        ]);
        DB::table('umi_v2_stock_share_moves')->insert([
            'request_key' => 'stock-fixture', 'kind' => 'entitlement',
            'to_member_id' => $member->id, 'shares' => '2',
            'reason' => '本地股票权益测试记录', 'created_at' => \App\Services\Umi\V2\FundedTime::database(now()),
        ]);
        $service = app(StockShares::class);
        DB::table('umi_v2_live_settings')->where('id', 1)->update(['stock_transfer_enabled' => false]);
        try {
            $service->toTradingWallet(2, '1', 'blocked-switch');
            self::fail('Disabled transfer should fail');
        } catch (\DomainException $error) {
            self::assertSame('股票划转暂未开放。', $error->getMessage());
        }
        DB::table('umi_v2_live_settings')->where('id', 1)->update(['stock_transfer_enabled' => true]);
        DB::table('wallets')->where('user_id', 1)->where('currency_id', 3)
            ->update(['balance_in_trade' => '0']);
        try {
            $service->toTradingWallet(2, '1', 'blocked-inventory');
            self::fail('Unfunded stock pool should fail');
        } catch (\DomainException $error) {
            self::assertSame('股票资金池份额不足。', $error->getMessage());
        }
        DB::table('wallets')->where('user_id', 1)->where('currency_id', 3)
            ->update(['balance_in_trade' => '2']);
        DB::table('markets')->where('name', 'HK08379-USDT')->update(['trade_status' => false]);
        try {
            $service->toTradingWallet(2, '1', 'blocked-market');
            self::fail('Disabled market should fail');
        } catch (\DomainException $error) {
            self::assertSame('盈证国际交易市场暂未开放。', $error->getMessage());
        }
        DB::table('markets')->where('name', 'HK08379-USDT')->update(['trade_status' => true]);
        DB::table('users')->where('id', 2)->update(['is_xm' => true]);
        try {
            $service->toTradingWallet(2, '1', 'blocked-virtual');
            self::fail('Virtual user should not receive real product units');
        } catch (\DomainException $error) {
            self::assertSame('股票划转账户暂不可用。', $error->getMessage());
        }
        DB::table('users')->where('id', 2)->update(['is_xm' => false]);
        self::assertSame('0', Decimal::display((string) DB::table('wallets')->where('user_id', 2)
            ->where('currency_id', 3)->value('balance_in_trade')));
        self::assertSame('2', Decimal::display((string) DB::table('umi_v2_stock_share_accounts')
            ->where('member_id', $member->id)->value('available_shares')));
        $move = $service->toTradingWallet(2, '1', 'stock-export');
        self::assertSame('exchange_transfer', $move->kind);
        try {
            $service->toTradingWallet(2, '2', 'stock-export');
            self::fail('Conflicting replay should fail');
        } catch (\DomainException $error) {
            self::assertSame('操作编号已用于其他股票记录。', $error->getMessage());
        }
        self::assertSame(1, DB::table('umi_v2_stock_share_moves')
            ->where('kind', 'exchange_transfer')->count());
        self::assertTrue($service->audit()['ok']);
    }

    public function test_new_members_require_umi_code_and_old_members_keep_original_parent(): void
    {
        try {
            $this->intake->enroll(2, null, 'missing-code');
            self::fail('A new member must provide a UMI code');
        } catch (\DomainException $e) {
            self::assertSame('请填写 UMI 专属邀请码。', $e->getMessage());
        }
        self::assertSame(0, DB::table('umi_v2_members')->count());
        Schema::create('umi_legacy_accounts', static function (Blueprint $t): void {
            $t->unsignedBigInteger('legacy_id')->primary();
            $t->unsignedBigInteger('user_id')->unique();
            $t->unsignedBigInteger('parent_legacy_id')->nullable();
            $t->string('activation_status');
        });
        DB::table('umi_legacy_accounts')->insert([
            ['legacy_id' => 101, 'user_id' => 2, 'parent_legacy_id' => null,
                'activation_status' => 'activated'],
            ['legacy_id' => 102, 'user_id' => 3, 'parent_legacy_id' => 101,
                'activation_status' => 'activated'],
        ]);
        $child = $this->intake->enroll(3, null, 'old-child');
        $parent = DB::table('umi_v2_members')->where('user_id', 2)->first();
        self::assertNotNull($parent);
        self::assertSame((int) $parent->id, (int) DB::table('umi_v2_sponsor_edges')
            ->where('child_member_id', $child->id)->value('parent_member_id'));
        self::assertSame('resolved', DB::table('umi_v2_legacy_parent_links')
            ->where('member_id', $child->id)->value('status'));
        self::assertSame($child->id, app(MemberEnrollment::class)
            ->activateHistorical(3, 'admin-check', 1)->id);
        self::assertSame(1, DB::table('umi_v2_member_activation_audit')->count());
        self::assertFalse(str_contains($child->member_code, 'V2'));
    }

    public function test_unavailable_historical_parent_is_preserved_then_linked_on_activation(): void
    {
        Schema::create('umi_legacy_accounts', static function (Blueprint $t): void {
            $t->unsignedBigInteger('legacy_id')->primary();
            $t->unsignedBigInteger('user_id')->nullable()->unique();
            $t->unsignedBigInteger('parent_legacy_id')->nullable();
            $t->string('activation_status');
        });
        DB::table('umi_legacy_accounts')->insert([
            ['legacy_id' => 201, 'user_id' => null, 'parent_legacy_id' => null,
                'activation_status' => 'pending'],
            ['legacy_id' => 202, 'user_id' => 3, 'parent_legacy_id' => 201,
                'activation_status' => 'activated'],
        ]);
        $child = $this->intake->enroll(3, null, 'pending-child');
        self::assertSame('pending', DB::table('umi_v2_legacy_parent_links')
            ->where('member_id', $child->id)->value('status'));
        self::assertSame(0, DB::table('umi_v2_sponsor_edges')->count());
        DB::table('umi_legacy_accounts')->where('legacy_id', 201)->update([
            'user_id' => 2, 'activation_status' => 'activated',
        ]);
        $parent = $this->intake->enroll(2, null, 'late-parent');
        self::assertSame((int) $parent->id, (int) DB::table('umi_v2_sponsor_edges')
            ->where('child_member_id', $child->id)->value('parent_member_id'));
        self::assertSame('resolved', DB::table('umi_v2_legacy_parent_links')
            ->where('member_id', $child->id)->value('status'));
    }

    public function test_replayed_intake_cannot_move_the_same_wallet_balance_twice(): void
    {
        $this->rootMember(2, 'enroll-once');
        $first = $this->intake->create(2, 'spot', '100', 'join-once');
        $second = $this->intake->create(2, 'spot', '100', 'join-once');
        self::assertSame($first->id, $second->id);
        self::assertSame(1, DB::table('umi_v2_live_wallet_moves')->count());
        self::assertSame(1, DB::table('umi_v2_cycles')->count());
        self::assertSame('10', Decimal::display((string) DB::table('wallets')
            ->where('user_id', 2)->where('currency_id', 1)->value('balance_in_trade')));
        self::assertSame(0, DB::table('umi_v2_live_burn_proofs')->count());
    }

    public function test_confirmed_burn_is_recorded_even_when_stock_quote_is_temporarily_unavailable(): void
    {
        $this->rootMember(2, 'enroll-quote');
        $intent = $this->intake->create(2, 'spot', '100', 'join-quote');
        $this->finalizeLot((int) DB::table('umi_v2_live_burn_lots')
            ->where('cycle_id', $intent->cycle_id)->value('id'));
        $this->settleCompleteDay(now('Asia/Shanghai')->toDateString(), '0.01', 1);
        $this->withdrawal->transfer(2, (int) $intent->cycle_id,
            'static', '1', 'transfer-quote');
        $paid = $this->withdrawal->request(2, '1', 'withdraw-quote');
        $lotId = (int) DB::table('umi_v2_live_burn_lots')
            ->where('withdrawal_id', $paid->id)->value('id');
        DB::table('umi_v2_live_quotes')->where('asset', 'HK08379_USDT')->delete();
        $this->finalizeLot($lotId);
        self::assertSame('confirmed', DB::table('umi_v2_live_burn_lots')->find($lotId)->status);
        self::assertSame(0, DB::table('umi_v2_stock_point_entries')->count());
        DB::table('umi_v2_live_quotes')->insert([
            'asset' => 'HK08379_USDT', 'price' => '0.1', 'source' => 'fixture',
            'source_ref' => 'quote-restored',
            'observed_at' => \App\Services\Umi\V2\FundedTime::database(DB::table('umi_v2_live_burn_lots')->find($lotId)->confirmed_at),
            'approved_by' => 1, 'created_at' => \App\Services\Umi\V2\FundedTime::database(now()),
        ]);
        self::assertTrue($this->burn->creditPointsForLot($lotId));
        self::assertSame(1, DB::table('umi_v2_stock_point_entries')->count());
    }

    public function test_insufficient_pool_inventory_rolls_back_entire_day(): void
    {
        DB::table('wallets')->where('user_id', 1)->update(['balance_in_wallet' => '0']);
        $this->rootMember(2, 'enroll-short');
        $intent = $this->intake->create(2, 'spot', '100', 'join-short');
        $this->finalizeLot((int) DB::table('umi_v2_live_burn_lots')
            ->where('cycle_id', $intent->cycle_id)->value('id'));
        try {
            $this->settleCompleteDay(now('Asia/Shanghai')->toDateString(), '0.01', 1);
            self::fail('Unfunded static release must fail.');
        } catch (\DomainException $expected) {
            self::assertStringContainsString('资金池余额不足', $expected->getMessage());
        }
        self::assertSame(0, DB::table('umi_v2_live_daily_runs')->count());
        self::assertSame(0, DB::table('umi_v2_release_events')->count());
        self::assertSame('0', Decimal::display((string) DB::table('umi_v2_cycles')
            ->where('id', $intent->cycle_id)->value('released_umi')));
    }

    public function test_withdrawal_waits_for_extra_umi_without_paying_partially(): void
    {
        $this->rootMember(3, 'enroll-topup');
        $intent = $this->intake->create(3, 'spot', '100', 'join-topup');
        $this->finalizeLot((int) DB::table('umi_v2_live_burn_lots')
            ->where('cycle_id', $intent->cycle_id)->value('id'));
        $this->settleCompleteDay(now('Asia/Shanghai')->toDateString(), '0.01', 1);
        $this->withdrawal->transfer(3, (int) $intent->cycle_id,
            'static', '1', 'transfer-topup');
        $withdrawal = $this->withdrawal->request(3, '1', 'withdraw-topup');
        self::assertSame('awaiting_topup', $withdrawal->status);
        self::assertSame('0', Decimal::display((string) $withdrawal->paid_umi));
        self::assertSame('0.3', Decimal::display((string) DB::table('umi_v2_live_withdrawal_funding')
            ->where('withdrawal_id', $withdrawal->id)->value('remaining_umi')));
        self::assertSame(0, DB::table('umi_v2_live_burn_lots')
            ->where('withdrawal_id', $withdrawal->id)->count());
    }

    public function test_all_nine_ranks_use_only_current_active_small_branch_volume(): void
    {
        DB::table('users')->insert(['id' => 4]);
        $root = $this->rootMember(2, 'rank-root');
        $large = $this->intake->enroll(3, $root->member_code, 'rank-large');
        $small = $this->intake->enroll(4, $root->member_code, 'rank-small');
        $policyId = (int) $this->intake->policy()->id;
        $cycle = function (object $member, string $value, int $number,
            string $status = 'active') use ($policyId): int {
            $id = DB::table('umi_v2_cycles')->insertGetId([
                'member_id' => $member->id, 'policy_version_id' => $policyId,
                'cycle_number' => $number,
                'activation_request_key' => 'live:rank:' . $member->id . ':' . $number,
                'multiplier' => 5, 'principal_umi' => $value,
                'usd_quote_per_umi' => '1', 'principal_usd' => $value,
                'cap_umi' => Decimal::mul($value, '5'), 'status' => $status,
                'starts_on' => now('Asia/Shanghai')->toDateString(),
                'created_at' => \App\Services\Umi\V2\FundedTime::database(now()), 'updated_at' => \App\Services\Umi\V2\FundedTime::database(now()),
            ]);
            DB::table('umi_v2_live_intents')->insert([
                'member_id' => $member->id,
                'request_key' => 'rank-intent:' . $id, 'source' => 'wallet',
                'status' => 'active', 'amount_umi' => $value,
                'umi_usd_quote' => '1', 'quote_source' => 'fixture:rank',
                'quote_at' => \App\Services\Umi\V2\FundedTime::database(now()), 'cycle_id' => $id,
                'created_at' => \App\Services\Umi\V2\FundedTime::database(now()), 'updated_at' => \App\Services\Umi\V2\FundedTime::database(now()),
            ]);
            return $id;
        };
        $cycle($root, '100', 1);
        $cycle($large, '20000000', 1);
        $smallCycle = $cycle($small, '500', 1);
        $cycle($small, '20000000', 2, 'completed');
        $refresh = new \ReflectionMethod(FundedSettlement::class, 'refreshRanks');
        foreach ([1 => '500', 2 => '3000', 3 => '10000', 4 => '100000',
            5 => '300000', 6 => '1000000', 7 => '3000000',
            8 => '5000000', 9 => '10000000'] as $level => $volume) {
            DB::table('umi_v2_cycles')->where('id', $smallCycle)
                ->update(['principal_umi' => $volume, 'principal_usd' => $volume]);
            $refresh->invoke($this->settlement);
            self::assertSame($level, (int) DB::table('umi_v2_members')
                ->where('id', $root->id)->value('level'));
        }
    }

    public function test_dynamic_rewards_fill_the_oldest_active_cycle_first(): void
    {
        $parent = $this->rootMember(2, 'fifo-parent');
        $child = $this->intake->enroll(3, $parent->member_code, 'fifo-child');
        $first = $this->intake->create(2, 'spot', '100', 'fifo-first');
        $this->finalizeLot((int) DB::table('umi_v2_live_burn_lots')
            ->where('cycle_id', $first->cycle_id)->value('id'));
        DB::table('wallets')->where('user_id', 2)->update(['balance_in_trade' => '110']);
        $second = $this->intake->create(2, 'spot', '100', 'fifo-second');
        $this->finalizeLot((int) DB::table('umi_v2_live_burn_lots')
            ->where('cycle_id', $second->cycle_id)->value('id'));
        $method = new \ReflectionMethod(FundedSettlement::class, 'dynamic');
        $day = now('Asia/Shanghai')->toDateString();
        $policy = (int) $this->intake->policy()->id;
        $method->invoke($this->settlement, (int) $parent->id, 'team', $day,
            (int) $child->id, 'fifo-seed', '299', 'fifo-seed', $policy, 1, 1);
        $result = $method->invoke($this->settlement, (int) $parent->id, 'team', $day,
            (int) $child->id, 'fifo-cross', '10', 'fifo-cross', $policy, 1, 1);
        self::assertSame('10', Decimal::display($result['paid']));
        self::assertSame('completed', DB::table('umi_v2_cycles')->find($first->cycle_id)->status);
        self::assertSame('300', Decimal::display((string) DB::table('umi_v2_cycles')
            ->find($first->cycle_id)->released_umi));
        self::assertSame('9', Decimal::display((string) DB::table('umi_v2_cycles')
            ->find($second->cycle_id)->team_released_umi));
    }

    public function test_chain_and_funding_wallet_cannot_bypass_spot_intake(): void
    {
        $this->rootMember(2, 'chain-member');
        $depositId=$this->creditedDeposit(2,'100');
        foreach (['wallet','verified_chain'] as $source) {
            try {$this->intake->create(2,$source,'100','blocked-'.$source);self::fail('Only spot intake allowed');}
            catch (\DomainException $e) {self::assertStringContainsString('现货',$e->getMessage());}
        }
        self::assertNull($this->intake->bindVerifiedDeposit($depositId));
        self::assertNull(app(\App\Services\Umi\V2\FundedInboundBinder::class)->bind($depositId));
        DB::table('wallets')->where('user_id',2)->where('currency_id',1)->update(['balance_in_trade'=>'0','balance_in_order'=>'100']);
        try {$this->intake->create(2,'spot','100','short-spot');self::fail('Wallet/frozen funds cannot fund intake');}
        catch (\DomainException) {self::assertSame(0,DB::table('umi_v2_live_intents')->count());}
        self::assertSame('100',Decimal::display((string)DB::table('wallets')->where('user_id',2)->where('currency_id',1)->value('balance_in_wallet')));
        self::assertSame('100',Decimal::display((string)DB::table('wallets')->where('user_id',2)->where('currency_id',1)->value('balance_in_order')));
        self::assertSame(0,DB::table('umi_v2_live_wallet_moves')->count());
    }

    public function test_only_explicit_spot_transfer_completes_thirty_percent_topup_once(): void
    {
        $this->rootMember(3, 'topup-member');
        $intent = $this->intake->create(3, 'spot', '100', 'topup-join');
        $this->finalizeLot((int) DB::table('umi_v2_live_burn_lots')->where('cycle_id', $intent->cycle_id)->value('id'));
        $this->settleCompleteDay(now('Asia/Shanghai')->toDateString(), '0.01', 1);
        $this->withdrawal->transfer(3, (int) $intent->cycle_id,'static', '1', 'topup-transfer');
        $withdrawal = $this->withdrawal->request(3, '1', 'topup-request');
        self::assertSame('awaiting_topup', $withdrawal->status);
        $depositId = $this->creditedDeposit(3, '0.3');
        self::assertNull($this->withdrawal->bindVerifiedTopup($depositId));
        try {$this->withdrawal->topupFromSpot(3,$withdrawal->id,'spot-topup');self::fail('No spot funds');}
        catch (\DomainException) {self::assertSame('awaiting_topup',DB::table('umi_v2_withdrawals')->find($withdrawal->id)->status);}
        $this->rootMember(2,'other-member');
        try {$this->withdrawal->topupFromSpot(2,$withdrawal->id,'other-topup');self::fail('Ownership required');}
        catch (\DomainException) {self::assertSame('awaiting_topup',DB::table('umi_v2_withdrawals')->find($withdrawal->id)->status);}
        DB::table('wallets')->where('user_id',3)->where('currency_id',1)->update(['balance_in_trade'=>'0.3']);
        $paid=$this->withdrawal->topupFromSpot(3,$withdrawal->id,'spot-topup');
        self::assertSame('paid',$paid->status);
        self::assertSame($paid->id,$this->withdrawal->topupFromSpot(3,$withdrawal->id,'retry')->id);
        self::assertSame('1',Decimal::display((string)$paid->paid_umi));
        self::assertSame('0',Decimal::display((string)DB::table('wallets')->where('user_id',3)->where('currency_id',1)->value('balance_in_trade')));
        self::assertSame('1.3',Decimal::display((string)DB::table('wallets')->where('user_id',3)->where('currency_id',1)->value('balance_in_wallet')));
        self::assertSame(1,DB::table('umi_v2_live_wallet_moves')->where('purpose','withdrawal_spot_topup')->count());
        self::assertSame(1, DB::table('umi_v2_live_burn_lots')->where('withdrawal_id', $withdrawal->id)->count());
    }

    public function test_v4_peer_award_uses_only_direct_child_paid_grade_income(): void
    {
        DB::table('users')->insert([['id' => 4], ['id' => 5], ['id' => 6]]);
        DB::table('wallets')->where('user_id', 1)->update(['balance_in_wallet' => '10000000']);
        $a = $this->rootMember(2, 'peer-a');
        $b = $this->intake->enroll(3, $a->member_code, 'peer-b');
        $c = $this->intake->enroll(4, $b->member_code, 'peer-c');
        $d = $this->intake->enroll(5, $b->member_code, 'peer-d');
        $e = $this->intake->enroll(6, $a->member_code, 'peer-e');
        foreach ([[$a, '5000'], [$b, '5000'], [$c, '100000'],
            [$d, '100000'], [$e, '100000']] as [$member, $amount]) {
            $this->seedActiveCycle($member, $amount);
        }
        $day = now('Asia/Shanghai')->toDateString();
        $this->settleCompleteDay($day, '0.01', 1);
        self::assertSame(4, (int) DB::table('umi_v2_members')->where('id', $a->id)->value('level'));
        self::assertSame(4, (int) DB::table('umi_v2_members')->where('id', $b->id)->value('level'));
        $peer = DB::table('umi_v2_live_peer_sources')
            ->where('parent_member_id', $a->id)
            ->where('child_member_id', $b->id)->first();
        self::assertNotNull($peer);
        self::assertSame('800', Decimal::display((string) $peer->confirmed_grade_umi));
        self::assertSame('80', Decimal::display((string) $peer->candidate_umi));
        self::assertSame('80', Decimal::display((string) $peer->released_umi));
    }

    public function test_closed_release_cannot_export_or_register_even_with_database_switches_on(): void
    {
        $member = $this->rootMember(2, 'guard-member');
        DB::table('umi_v2_stock_share_accounts')->insert(['member_id' => $member->id,
            'available_shares' => '2', 'locked_shares' => '0', 'created_at' => \App\Services\Umi\V2\FundedTime::database(now()), 'updated_at' => \App\Services\Umi\V2\FundedTime::database(now())]);
        config(['umi-v2.funded_enabled' => false]);
        foreach ([fn () => app(StockShares::class)->toTradingWallet(2, '1', 'closed-export'),
            fn () => app(MemberEnrollment::class)->enroll(3, $member->member_code, 'closed-enroll'),
            fn () => app(StockShares::class)->settleDue()] as $operation) {
            try { $operation(); self::fail('Closed release must reject financial writes'); }
            catch (\DomainException $e) { self::assertStringContainsString('暂未开放', $e->getMessage()); }
        }
        self::assertSame(0, DB::table('umi_v2_stock_share_moves')->count());
        self::assertSame('5', Decimal::display((string) DB::table('wallets')
            ->where('user_id', 1)->where('currency_id', 3)->value('balance_in_trade')));
        self::assertSame(1, DB::table('umi_v2_members')->count());
        self::assertFalse(app(StockShares::class)->tradingWalletState(2)['enabled']);
    }

    public function test_real_share_export_rejects_mixed_virtual_balances_without_partial_debits(): void
    {
        Schema::table('wallets', fn (Blueprint $t) => $t->decimal('balance_in_virtual_trade', 36, 18)->default(0));
        Schema::table('wallets', fn (Blueprint $t) => $t->decimal('balance_in_virtual_order', 36, 18)->default(0));
        $member = $this->rootMember(2, 'mixed-member');
        DB::table('umi_v2_stock_share_accounts')->insert(['member_id' => $member->id,
            'available_shares' => '2', 'locked_shares' => '0', 'created_at' => \App\Services\Umi\V2\FundedTime::database(now()), 'updated_at' => \App\Services\Umi\V2\FundedTime::database(now())]);
        foreach ([[2, 'balance_in_virtual_trade'], [1, 'balance_in_virtual_order']] as [$userId, $field]) {
            DB::table('wallets')->where('currency_id', 3)->update([
                'balance_in_virtual_trade' => '0', 'balance_in_virtual_order' => '0']);
            DB::table('wallets')->where('currency_id', 3)->where('user_id', $userId)->update([$field => '1']);
            self::assertFalse(app(StockShares::class)->tradingWalletState(2)['enabled']);
            try { app(StockShares::class)->toTradingWallet(2, '1', 'mixed-export'); self::fail('Mixed-domain transfer must fail'); }
            catch (\DomainException $e) { self::assertStringContainsString('账户类型', $e->getMessage()); }
            self::assertSame('2', Decimal::display((string) DB::table('umi_v2_stock_share_accounts')
                ->where('member_id', $member->id)->value('available_shares')));
        }
        self::assertSame(0, DB::table('umi_v2_stock_share_moves')->count());
    }

    public function test_maximum_and_receipts_show_only_member_inventory_and_public_fields(): void
    {
        $member = $this->rootMember(2, 'private-member');
        DB::table('umi_v2_stock_share_accounts')->insert(['member_id' => $member->id,
            'available_shares' => '8', 'locked_shares' => '0', 'created_at' => \App\Services\Umi\V2\FundedTime::database(now()), 'updated_at' => \App\Services\Umi\V2\FundedTime::database(now())]);
        $service = app(StockShares::class);
        self::assertSame('5.00000000', $service->tradingWalletState(2)['maximum']);
        $move = $service->toTradingWallet(2, '1', 'private-export');
        self::assertSame(['id','kind','shares','created_at'], array_keys(StockShares::publicReceipt($move)));
        self::assertSame(['id','kind','shares','created_at'], array_keys((array) $service->memberMovements($member->id)[0]));
        self::assertSame([], $service->memberMovements(999));
        self::assertSame('4.00000000', $service->tradingWalletState(2)['maximum']);
        self::assertSame($move->id, $service->toTradingWallet(2, '1', 'private-export')->id);
        self::assertSame(1, DB::table('umi_v2_stock_share_moves')->count());
    }

    public function test_operators_can_save_draft_when_release_is_closed_but_cannot_enable_incomplete_setup(): void
    {
        config(['umi-v2.funded_enabled' => false]);
        app()->forgetInstance(FundedReadiness::class);
        $draft = ['pool_user_id' => null, 'umi_network_id' => null, 'dedicated_address' => '',
            'intake_enabled' => false, 'settlement_enabled' => false,
            'withdrawal_enabled' => false, 'stock_transfer_enabled' => false];
        $service = app(FundedConfiguration::class);
        self::assertFalse($service->save($draft + ['revision'=>$service->revision()], 'draft', 1)['replayed']);
        self::assertTrue($service->save($draft + ['revision'=>$service->revision()], 'draft', 1)['replayed']);
        foreach (['intake_enabled','settlement_enabled','withdrawal_enabled','stock_transfer_enabled'] as $flag) {
            try { $service->save(array_replace($draft, [$flag => true,'revision'=>$service->revision()]), 'enable-'.$flag, 1);
                self::fail('Incomplete configuration cannot open money operations'); }
            catch (\DomainException) { self::assertFalse((bool) DB::table('umi_v2_live_settings')->find(1)->{$flag}); }
        }
        self::assertSame(1, DB::table('umi_v2_live_settings_audit')->count());
    }

    public function test_income_dashboard_uses_all_owned_events_and_actual_credit_day(): void
    {
        $previous=\Carbon\Carbon::getTestNow();$immutable=\Carbon\CarbonImmutable::getTestNow();
        // The PostgreSQL fixture stores naive writer timestamps in UTC. Exercise
        // the business-day boundary with that explicit fixture clock.
        $originalZone=config('app.timezone');$phpZone=date_default_timezone_get();
        config(['app.timezone'=>'UTC']);date_default_timezone_set('UTC');
        $start=\Carbon\CarbonImmutable::parse('2026-08-01T00:00:00+08:00');
        $clock=static function($at): void {\Carbon\Carbon::setTestNow($at);\Carbon\CarbonImmutable::setTestNow($at);};
        try {
            $clock($start);$member=$this->rootMember(2,'income-summary');$other=$this->rootMember(3,'other-income');
            $this->seedActiveCycle($member,'100');$this->seedActiveCycle($other,'100');
            $cycle=DB::table('umi_v2_cycles')->where('member_id',$member->id)->first();
            $otherCycle=DB::table('umi_v2_cycles')->where('member_id',$other->id)->first();
            $store=app(CycleStore::class);
            for($i=0;$i<46;$i++) {
                $at=$i===45?$start->addDays($i):$start->addDays($i)->endOfDay();$clock($at);
                $store->releaseStatic($cycle->id,'income-day-'.$i,$at->toDateString(),'0.001',$cycle->policy_version_id);
            }
            $store->releaseStatic($otherCycle->id,'unrelated-income',now('Asia/Shanghai')->toDateString(),'0.1',$otherCycle->policy_version_id);
            $state=app(\App\Services\Umi\V2\FundedDashboard::class)->member(2);
            self::assertCount(40,$state['releases']);
            self::assertSame('4.6',Decimal::display($state['income_summary']['total_umi']));
            self::assertSame('0.1',Decimal::display($state['income_summary']['today_umi']));
            self::assertSame('4.6',Decimal::display($state['income_summary']['by_kind']['static']));
            $records=app(\App\Services\Umi\V2\RecordCenter::class)->read(['dataset'=>'releases','source'=>'static','page'=>2],2);
            self::assertSame(46,$records['total']);self::assertCount(21,$records['rows']);
        } finally {\Carbon\Carbon::setTestNow($previous);\Carbon\CarbonImmutable::setTestNow($immutable);config(['app.timezone'=>$originalZone]);date_default_timezone_set($phpZone);}
    }

    public function test_dashboard_pending_total_is_not_limited_to_visible_thirty_records_and_get_does_not_unlock(): void
    {
        $member = $this->rootMember(2, 'dashboard-member');
        DB::table('wallets')->where('user_id', 2)->where('currency_id', 1)->update(['balance_in_trade' => '10000']);
        $intent = $this->intake->create(2, 'spot', '10000', 'summary-intake');
        $this->finalizeLot((int) DB::table('umi_v2_live_burn_lots')->where('cycle_id', $intent->cycle_id)->value('id'));
        $this->settleCompleteDay(now('Asia/Shanghai')->toDateString(), '0.01', 1);
        $this->withdrawal->transfer(2, (int) $intent->cycle_id, 'static', '80', 'summary-income');
        for ($i = 0; $i < 45; $i++) {
            $paid = $this->withdrawal->request(2, '1', 'summary-withdraw-'.$i);
            $this->finalizeLot((int) DB::table('umi_v2_live_burn_lots')->where('withdrawal_id', $paid->id)->value('id'));
        }
        DB::table('umi_v2_live_point_terms')->update(['eligible_at' => \App\Services\Umi\V2\FundedTime::database(now()->subMonth()), 'unlock_at' => \App\Services\Umi\V2\FundedTime::database(now()->addMonth())]);
        $state = app(\App\Services\Umi\V2\FundedDashboard::class)->member(2);
        self::assertSame('13.5', Decimal::display($state['pending_points']));
        self::assertCount(30, $state['points']);
        self::assertSame(0, DB::table('umi_v2_stock_share_moves')->count());
        $service = app(StockShares::class);
        self::assertSame(45, $service->settleDue()['confirmed']);
        self::assertSame(['confirmed' => 0, 'unlocked' => 0], $service->settleDue());
        self::assertTrue($service->audit()['ok']);
    }

    public function test_missing_schema_returns_closed_member_state_without_querying_financial_tables(): void
    {
        Schema::drop('umi_v2_stock_share_moves');
        $state = app(\App\Services\Umi\V2\FundedDashboard::class)->member(2);
        self::assertFalse($state['ready']);
        self::assertFalse($state['enrollment_enabled']);
        self::assertNull($state['member']);
    }

    public function test_postgres_concurrent_retries_return_one_receipt_and_competing_exports_cannot_overdraw(): void
    {
        if (DB::getDriverName() !== 'pgsql' || !function_exists('pcntl_fork')) {
            self::markTestSkipped('Requires the explicitly isolated PostgreSQL fixture and pcntl');
        }
        $member = $this->rootMember(2, 'concurrent-member');
        DB::table('umi_v2_stock_share_accounts')->insert(['member_id' => $member->id,
            'available_shares' => '10', 'locked_shares' => '0', 'created_at' => \App\Services\Umi\V2\FundedTime::database(now()), 'updated_at' => \App\Services\Umi\V2\FundedTime::database(now())]);
        $results = $this->parallelExports([['1','same-key'],['1','same-key']]);
        self::assertSame($results[0]['id'], $results[1]['id']);
        self::assertSame(1, DB::table('umi_v2_stock_share_moves')->count());
        $results = $this->parallelExports([['3','competing-a'],['3','competing-b']]);
        self::assertSame(1, count(array_filter($results, fn ($r) => isset($r['id']))));
        self::assertSame(1, count(array_filter($results, fn ($r) => ($r['error'] ?? '') === '股票资金池份额不足。')));
        self::assertSame('1', Decimal::display((string) DB::table('wallets')->where('user_id', 1)
            ->where('currency_id', 3)->value('balance_in_trade')));
        self::assertSame('4', Decimal::display((string) DB::table('wallets')->where('user_id', 2)
            ->where('currency_id', 3)->value('balance_in_trade')));
        self::assertSame('6', Decimal::display((string) DB::table('umi_v2_stock_share_accounts')
            ->where('member_id', $member->id)->value('available_shares')));
    }

    public function test_postgres_stock_receipts_cannot_be_modified_or_deleted(): void
    {
        if (DB::getDriverName() !== 'pgsql') { self::markTestSkipped('PostgreSQL protection test'); }
        $member = $this->rootMember(2, 'immutable-member');
        DB::table('umi_v2_stock_share_accounts')->insert(['member_id' => $member->id,
            'available_shares' => '2', 'locked_shares' => '0', 'created_at' => \App\Services\Umi\V2\FundedTime::database(now()), 'updated_at' => \App\Services\Umi\V2\FundedTime::database(now())]);
        $move = app(StockShares::class)->toTradingWallet(2, '1', 'immutable-export');
        foreach ([fn () => DB::table('umi_v2_stock_share_moves')->where('id', $move->id)->update(['shares'=>'2']),
            fn () => DB::table('umi_v2_stock_share_moves')->where('id', $move->id)->delete(),
            fn () => DB::statement('TRUNCATE umi_v2_stock_share_moves')] as $change) {
            try { $change(); self::fail('Historical receipt changed'); }
            catch (\Illuminate\Database\QueryException $e) { self::assertStringContainsString('append-only', $e->getMessage()); }
        }
        self::assertSame(1, DB::table('umi_v2_stock_share_moves')->count());
    }

    public function test_unified_history_keeps_member_ownership_and_paginates_all_original_entries(): void
    {
        Schema::create('umi_business_accounts', static function (Blueprint $t): void {
            $t->id(); $t->unsignedBigInteger('user_id'); $t->unsignedBigInteger('legacy_id')->nullable();
            $t->unsignedBigInteger('parent_id')->nullable(); $t->string('code'); $t->integer('level')->default(0);
            $t->boolean('fixture')->default(false); $t->decimal('quota_total', 36, 18)->default(0);
            $t->decimal('quota_used', 36, 18)->default(0);
        });
        Schema::create('umi_business_balances', static function (Blueprint $t): void {
            $t->id(); $t->string('bucket'); $t->string('asset'); $t->decimal('amount', 36, 18);
        });
        Schema::create('umi_business_entries', static function (Blueprint $t): void {
            $t->id(); $t->string('bucket'); $t->string('asset'); $t->decimal('delta', 36, 18);
            $t->decimal('balance_after', 36, 18); $t->string('description'); $t->timestamps();
        });
        foreach ([[1,2,'mine',false], [2,3,'other',false], [3,1,'fixture',true]] as [$id,$user,$code,$fixture]) {
            DB::table('umi_business_accounts')->insert(['id'=>$id,'user_id'=>$user,'code'=>$code,'fixture'=>$fixture]);
            DB::table('umi_business_balances')->insert(['bucket'=>"account:$id:wallet",'asset'=>'UMI','amount'=>'7']);
            for ($i=0;$i<45;$i++) DB::table('umi_business_entries')->insert(['bucket'=>"account:$id:wallet",'asset'=>'UMI',
                'delta'=>'1','balance_after'=>'7','description'=>$code,'created_at'=>now(),'updated_at'=>now()]);
        }
        $history = app(\App\Services\Umi\V2\UnifiedHistory::class);
        $first = $history->member(2); $second = $history->member(2,2);
        self::assertSame('mine',$first['code']); self::assertCount(40,$first['entries']);
        self::assertCount(5,$second['entries']); self::assertSame(45,$first['entry_pagination']['total']);
        self::assertSame(['mine'],array_unique(array_map(fn($e)=>$e->description,$first['entries'])));
        self::assertSame('wallet',$first['balances'][0]['pocket']); self::assertNull($history->member(1));
        self::assertSame(2,$history->admin('',1,null)['accounts']->total());
        self::assertNull($history->admin('',1,3)['selected']);
        self::assertSame(0,DB::table('umi_v2_release_events')->count());
    }

    public function test_stock_clock_uses_sixty_days_then_ninety_from_actual_confirmation(): void
    {
        $member=$this->rootMember(2,'clock-member');
        $intent=$this->intake->create(2,'spot','100','clock-join');
        $this->finalizeLot((int)DB::table('umi_v2_live_burn_lots')->where('cycle_id',$intent->cycle_id)->value('id'));
        $this->settleCompleteDay(now('Asia/Shanghai')->toDateString(),'0.01',1);
        $this->withdrawal->transfer(2,(int)$intent->cycle_id,'static','0.8','clock-income');
        $paid=$this->withdrawal->request(2,'0.8','clock-withdraw');
        $this->finalizeLot((int)DB::table('umi_v2_live_burn_lots')->where('withdrawal_id',$paid->id)->value('id'));
        $term=DB::table('umi_v2_live_point_terms')->first();
        $acquired=\Carbon\CarbonImmutable::parse($term->acquired_at)->utc();
        self::assertNull($term->unlock_at);
        self::assertTrue($acquired->addDays(60)->equalTo(\Carbon\CarbonImmutable::parse($term->eligible_at)));
        $service=app(StockShares::class);
        $this->travelTo($acquired->addDays(60)->subSecond());
        self::assertSame(['confirmed'=>0,'unlocked'=>0],$service->settleDue());
        // A late worker cannot shorten the ninety-day holding period.
        $confirmed=$acquired->addDays(160); $this->travelTo($confirmed);
        self::assertSame(['confirmed'=>1,'unlocked'=>0],$service->settleDue());
        $term=DB::table('umi_v2_live_point_terms')->first();
        self::assertTrue($confirmed->addDays(90)->equalTo(\Carbon\CarbonImmutable::parse($term->unlock_at)));
        self::assertSame('0',Decimal::display(app(\App\Services\Umi\V2\FundedDashboard::class)->member(2)['pending_points']));
        $this->travelTo($confirmed->addDays(90)->subSecond());
        self::assertSame(['confirmed'=>0,'unlocked'=>0],$service->settleDue());
        $this->travelTo($confirmed->addDays(90));
        self::assertSame(['confirmed'=>0,'unlocked'=>1],$service->settleDue());
        self::assertSame(['confirmed'=>0,'unlocked'=>0],$service->settleDue());
        self::assertTrue($service->audit()['ok']);
    }

    public function test_account_conversion_is_atomic_once_only_and_pending_principal_can_be_topped_up(): void
    {
        $this->cutoverFixture();
        $service=app(\App\Services\Umi\V2\AccountCutover::class);
        $preview=$service->preview();
        self::assertSame('145',$preview['principal_umi']);
        self::assertSame(2,$preview['accounts']);
        $quoteId=(int)app(FundedConfiguration::class)->quote('UMI_USDT')->id;
        $start=now('Asia/Shanghai')->addDay()->toDateString();
        try {$service->run($preview['sha256'],$quoteId,$start,1,1);self::fail('Conversion defaults closed');}
        catch (\DomainException) { self::assertSame(0,DB::table('umi_v2_account_merges')->count()); }
        DB::table('umi_v2_live_settings')->where('id',1)->update(['account_cutover_enabled'=>true]);
        DB::table('umi_business_balances')->where('bucket','account:1:main')->update(['amount'=>'101']);
        try {$service->run($preview['sha256'],$quoteId,$start,1,1);self::fail('Changed source must be reviewed again');}
        catch (\DomainException) {self::assertSame(0,DB::table('umi_v2_cycles')->count());}
        DB::table('umi_business_balances')->where('bucket','account:1:main')->update(['amount'=>'100']);
        $result=$service->run($preview['sha256'],$quoteId,$start,1,1);
        self::assertSame(1,$result['active_cycles']); self::assertSame(1,$result['pending_principals']);
        self::assertTrue($service->run($preview['sha256'],$quoteId,$start,1,1)['replayed']);
        self::assertSame(2,DB::table('umi_v2_account_merges')->count());
        self::assertSame(0,DB::table('umi_v2_stock_point_entries')->count());
        self::assertSame(0,DB::table('umi_v2_live_burn_lots')->count());
        self::assertSame('0',Decimal::display((string)DB::table('umi_business_balances')->where('bucket','like','account:%')->sum('amount')));
        self::assertSame('145',Decimal::display((string)DB::table('umi_business_balances')->where('bucket','system:custody')->value('amount')));
        self::assertSame('0',Decimal::display((string)DB::table('umi_business_entries')->sum('delta')));
        $previewTopup=$this->intake->preview(3,'80');
        self::assertSame('100',$previewTopup['principal_umi']);
        $intent=$this->intake->create(3,'spot','80','pending-topup');
        self::assertSame('100',Decimal::display((string)DB::table('umi_v2_cycles')->find($intent->cycle_id)->principal_umi));
        self::assertSame('80',Decimal::display((string)DB::table('umi_v2_live_burn_lots')->value('amount_umi')));
        self::assertSame('20',Decimal::display((string)DB::table('wallets')->where('user_id',3)->where('currency_id',1)->value('balance_in_trade')));
        self::assertSame('0',Decimal::display((string)DB::table('umi_v2_pending_principals')->value('amount_umi')));
        self::assertSame($intent->id,$this->intake->create(3,'spot','80','pending-topup')->id);
        self::assertSame(2,DB::table('umi_v2_cycles')->count());
    }

    public function test_unbound_or_non_umi_balances_block_conversion_without_partial_credits(): void
    {
        $this->cutoverFixture();
        DB::table('umi_business_accounts')->where('id',2)->update(['user_id'=>null]);
        DB::table('umi_business_balances')->insert(['bucket'=>'account:1:main','asset'=>'USDT','amount'=>'1']);
        $service=app(\App\Services\Umi\V2\AccountCutover::class); $preview=$service->preview();
        self::assertSame(2,$preview['blocked']);
        DB::table('umi_v2_live_settings')->where('id',1)->update(['account_cutover_enabled'=>true]);
        $this->expectException(\DomainException::class);
        try {$service->run($preview['sha256'],1,now()->toDateString(),1,1);}
        finally {self::assertSame(0,DB::table('umi_v2_account_merges')->count());self::assertSame(0,DB::table('umi_v2_cycles')->count());}
    }

    public function test_unified_daily_job_is_closed_by_default_and_replaces_original_daily_job(): void
    {
        $this->cutoverFixture();
        $service=app(\App\Services\Umi\V2\AccountCutover::class);$source=$service->preview();
        DB::table('umi_v2_live_settings')->where('id',1)->update(['account_cutover_enabled'=>true]);
        $quote=(int)app(FundedConfiguration::class)->quote('UMI_USDT')->id;
        $day=now('Asia/Shanghai')->addDay()->toDateString();
        $service->run($source['sha256'],$quote,$day,1,1);
        $this->travelTo(\Carbon\CarbonImmutable::parse($day,'Asia/Shanghai')->addDay()->startOfDay());
        self::assertSame(0,\Illuminate\Support\Facades\Artisan::call('umi:settle-funded'));
        self::assertSame(2,DB::table('umi_business_operations')->count());
        DB::table('umi_v2_live_settings')->where('id',1)->update(['settlement_enabled'=>false]);
        self::assertSame(0,\Illuminate\Support\Facades\Artisan::call('umi:settle-unified'));
        self::assertSame(0,DB::table('umi_v2_live_daily_runs')->count());
        DB::table('umi_v2_live_settings')->where('id',1)->update(['settlement_enabled'=>true]);
        self::assertSame(0,\Illuminate\Support\Facades\Artisan::call('umi:settle-unified'));
        self::assertSame(1,DB::table('umi_v2_live_daily_runs')->count());
        self::assertSame('1.25',Decimal::display((string)DB::table('umi_v2_release_events')->sum('released_umi')));
        self::assertSame(0,\Illuminate\Support\Facades\Artisan::call('umi:settle-unified'));
        self::assertSame(1,DB::table('umi_v2_live_daily_runs')->count());
    }

    public function test_cutover_requires_explicit_batch_and_rejects_changed_pockets_even_when_total_matches(): void
    {
        $this->cutoverFixture();
        DB::table('umi_v2_live_settings')->where('id',1)->update(['account_cutover_enabled'=>true]);
        $service=app(\App\Services\Umi\V2\AccountCutover::class);
        $preview=$service->preview();
        try {$service->run($preview['sha256'],1,now()->toDateString(),1);self::fail('Missing capture binding');}
        catch (\DomainException) {self::assertSame(0,DB::table('umi_v2_account_merges')->count());}
        DB::table('umi_business_balances')->where('bucket','account:1:main')->update(['amount'=>'99']);
        DB::table('umi_business_balances')->where('bucket','account:1:treasure')->update(['amount'=>'26']);
        $changed=$service->preview();
        self::assertSame('145',$changed['principal_umi']); self::assertSame(1,$changed['blocked']);
        self::assertFalse($changed['approved']);
        self::assertContains('balance:main',$changed['differences'][0]['issues']);
        $this->expectException(\DomainException::class);
        $service->run($preview['sha256'],1,now()->toDateString(),1,1);
    }

    public function test_capture_stager_cannot_self_approve_and_identity_changes_invalidate_approval(): void
    {
        $this->cutoverFixture();
        $service=app(\App\Services\Umi\V2\AccountCutover::class); $preview=$service->preview();
        try {app(\App\Services\Umi\V2\CaptureSnapshots::class)->approve(1,$preview['sha256'],now()->toIso8601String(),'已核定所有账户的来源和截止时间',1);self::fail('Self approval');}
        catch (\DomainException) {self::assertSame(1,DB::table('umi_v2_capture_approvals')->count());}
        DB::table('umi_business_accounts')->where('id',2)->update(['user_id'=>1]);
        self::assertFalse($service->preview()['approved']);
        DB::table('umi_v2_live_settings')->where('id',1)->update(['account_cutover_enabled'=>true]);
        $this->expectException(\DomainException::class);
        $service->run($preview['sha256'],1,now()->toDateString(),1,1);
    }

    public function test_record_center_queries_every_dataset_and_enforces_member_scope_with_full_export(): void
    {
        $a=$this->rootMember(2,'records-a'); $b=$this->rootMember(3,'records-b');
        $now=\App\Services\Umi\V2\FundedTime::database(now());
        foreach (range(1,61) as $n) DB::table('umi_v2_live_intents')->insert([
            'member_id'=>$n===61?$b->id:$a->id,'request_key'=>'record:'.$n,'source'=>'verified_chain',
            'status'=>'awaiting_credit','amount_umi'=>'0.125','umi_usd_quote'=>'1',
            'quote_source'=>$n===1?'=HYPERLINK("fixture.invalid")':'fixture',
            'quote_at'=>$now,'created_at'=>$now,'updated_at'=>$now]);
        $center=app(\App\Services\Umi\V2\RecordCenter::class);
        foreach (array_keys(\App\Services\Umi\V2\RecordCenter::DATASETS) as $dataset) {
            $r=$center->read(['dataset'=>$dataset],null); self::assertIsArray($r['columns'],$dataset);
        }
        $filters=['dataset'=>'intents','member_id'=>$b->id,'root_member_id'=>$b->id,'page'=>3];
        $r=$center->read($filters,2);
        self::assertSame(60,$r['total']); self::assertCount(10,$r['rows']);
        self::assertSame('7.5',Decimal::display($r['totals']['amount_umi']));
        foreach ($r['rows'] as $row) self::assertSame((int)$a->id,(int)$row->member_id);
        $e=$center->export($filters,2); self::assertSame(60,$e['count']);
        $bytes=stream_get_contents($e['stream']); fclose($e['stream']);
        $lines=explode("\n",trim(substr($bytes,3))); array_shift($lines); array_pop($lines);
        self::assertSame($e['sha256'],hash('sha256',implode("\n",$lines)."\n"));
        self::assertStringContainsString("'=HYPERLINK",$bytes); self::assertCount(61,$lines);
        self::assertSame(0,$center->read(['dataset'=>'intents','status'=>'active'],2)['total']);
        $this->expectException(\DomainException::class); $center->read(['dataset'=>'members'],2);
    }

    public function test_record_dates_use_business_timezone_and_admin_queries_require_umi_grants(): void
    {
        $a=$this->rootMember(2,'dates');
        foreach (['2026-10-01T15:59:59.900000Z','2026-10-01T16:00:00Z'] as $n=>$instant) {
            $time=\App\Services\Umi\V2\FundedTime::database(\Carbon\CarbonImmutable::parse($instant));
            DB::table('umi_v2_live_intents')->insert(['member_id'=>$a->id,'request_key'=>'boundary:'.$n,
                'source'=>'verified_chain','status'=>'awaiting_credit','amount_umi'=>'1','umi_usd_quote'=>'1',
                'quote_source'=>'fixture','quote_at'=>$time,'created_at'=>$time,'updated_at'=>$time]);
        }
        $r=app(\App\Services\Umi\V2\RecordCenter::class)->read(['dataset'=>'intents','date_from'=>'2026-10-02','date_to'=>'2026-10-02'],2);
        self::assertSame(1,$r['total']);
        self::assertTrue(\Carbon\CarbonImmutable::parse(DB::table('umi_v2_live_intents')->where('request_key','boundary:0')->value('created_at'))->equalTo(\Carbon\CarbonImmutable::parse('2026-10-01T15:59:59Z')));
        self::assertTrue(\Carbon\CarbonImmutable::parse($r['rows'][0]->created_at)->equalTo(\Carbon\CarbonImmutable::parse('2026-10-01T16:00:00Z')));
        if (!\Illuminate\Support\Facades\Route::has('admin.umi.records')) \Illuminate\Support\Facades\Route::middleware('web')->group(base_path('routes/admin.php'));
        foreach (\Illuminate\Support\Facades\Route::getRoutes() as $route) if (str_contains($route->uri(),'umi/operations') && $route->methods()[0]==='GET') {
            self::assertContains('role:'.\App\Support\UmiAdminAccess::roleGate(),$route->gatherMiddleware());
        }
    }

    public function test_team_metrics_match_live_cycle_rank_basis_and_shortfall_never_goes_negative(): void
    {
        $root=$this->rootMember(2,'team-root'); $child=$this->intake->enroll(3,$root->member_code,'team-child');
        DB::table('users')->insert(['id'=>4]); $other=$this->intake->enroll(4,$root->member_code,'team-other');
        $this->seedActiveCycle($child,'3000'); $this->seedActiveCycle($other,'500');
        $team=app(\App\Services\Umi\V2\TeamOverview::class)->inspect($root->id);
        self::assertSame(2,$team['team_count']); self::assertSame('500',Decimal::display($team['small_area_usdt']));
        self::assertSame(0,$team['projected_level']); self::assertFalse($team['personal_qualification_met']);
        self::assertSame('0',Decimal::display($team['next_level_gap_usdt']));
        $this->seedActiveCycle($root,'100'); self::assertSame(1,app(\App\Services\Umi\V2\TeamOverview::class)->inspect($root->id)['projected_level']);
    }

    public function test_new_capture_invalidates_old_approval_and_duplicate_identity_blocks_conversion(): void
    {
        $this->cutoverFixture(); $snapshots=app(\App\Services\Umi\V2\CaptureSnapshots::class);
        $batch=DB::table('umi_v2_capture_batches')->find(1); $payload=json_decode($batch->payload_json,true);
        $batch2=$snapshots->stage(['manifest_sha256'=>str_repeat('d',64),'source'=>'https://fixture.invalid',
            'started_at'=>now()->toIso8601String(),'finished_at'=>now()->toIso8601String(),
            'principal_umi'=>'145','payload'=>$payload['resources'],'accounts'=>$payload['accounts']],1);
        self::assertSame(2,(int)$batch2->id); $service=app(\App\Services\Umi\V2\AccountCutover::class);
        self::assertFalse($service->preview(1)['latest_batch']); self::assertFalse($service->preview()['approved']);
        self::assertSame(0,DB::table('umi_business_entries')->count()); self::assertSame(0,DB::table('umi_v2_members')->count());
        DB::table('umi_business_accounts')->where('id',2)->update(['legacy_id'=>101]);
        self::assertContains('duplicate_identity',$service->preview()['differences'][0]['issues']);
    }

    public function test_postgres_capture_evidence_rejects_update_delete_and_truncate(): void
    {
        if (DB::getDriverName()!=='pgsql') $this->markTestSkipped('PostgreSQL append-only triggers');
        $this->cutoverFixture();
        foreach (['umi_v2_capture_batches','umi_v2_capture_accounts','umi_v2_capture_approvals'] as $table)
            foreach (["UPDATE $table SET ".($table==='umi_v2_capture_accounts'?'legacy_id=legacy_id':'id=id'),"DELETE FROM $table","TRUNCATE $table CASCADE"] as $sql) {
                try {DB::transaction(fn()=>DB::statement($sql));self::fail('Evidence mutation allowed');}
                catch (\Illuminate\Database\QueryException) {self::assertGreaterThan(0,DB::table($table)->count());}
            }
    }

    public function test_capture_reader_rejects_modified_files_and_outside_directory_paths(): void
    {
        $root=sys_get_temp_dir().'/umi-manifest-fixture-'.bin2hex(random_bytes(8)); mkdir($root,0700);
        $file=$root.'/fixture.json'; file_put_contents($file,'{"fixture":true}');
        $manifest=['files'=>[['path'=>'fixture.json','sha256'=>hash_file('sha256',$file),'size_bytes'=>filesize($file)]]];
        file_put_contents($root.'/capture_manifest.json',json_encode($manifest)); file_put_contents($file,'{"fixture":false}');
        try {
            try {app(\App\Services\Umi\V2\CaptureSnapshots::class)->read($root);self::fail('Changed bytes accepted');}
            catch (\DomainException $e) {self::assertStringContainsString('校验值',$e->getMessage());}
            $manifest['files'][0]['path']='../outside.json'; file_put_contents($root.'/capture_manifest.json',json_encode($manifest));
            try {app(\App\Services\Umi\V2\CaptureSnapshots::class)->read($root);self::fail('Path escape accepted');}
            catch (\DomainException $e) {self::assertStringContainsString('缺失',$e->getMessage());}
        } finally {unlink($file);unlink($root.'/capture_manifest.json');rmdir($root);}
    }

    public function test_unbound_accounts_remain_blocked_without_false_duplicate_identity(): void
    {
        $this->cutoverFixture();
        DB::table('umi_business_accounts')->update(['user_id'=>null]);
        $preview=app(\App\Services\Umi\V2\AccountCutover::class)->preview();
        self::assertSame(2,$preview['blocked']);
        foreach ($preview['differences'] as $difference) {
            self::assertContains('identity',$difference['issues']);
            self::assertNotContains('duplicate_identity',$difference['issues']);
        }
    }

    public function test_record_exports_preserve_24_decimal_precision_on_postgres(): void
    {
        if (DB::getDriverName()!=='pgsql') $this->markTestSkipped('PostgreSQL exact numeric storage');
        $a=$this->rootMember(2,'precision'); $now=\App\Services\Umi\V2\FundedTime::database(now());
        $value='0.123456789012345678901234';
        foreach ([1,2] as $n) DB::table('umi_v2_live_intents')->insert(['member_id'=>$a->id,'request_key'=>'precision:'.$n,
            'source'=>'verified_chain','status'=>'awaiting_credit','amount_umi'=>$value,'umi_usd_quote'=>'1',
            'quote_source'=>'fixture','quote_at'=>$now,'created_at'=>$now,'updated_at'=>$now]);
        $center=app(\App\Services\Umi\V2\RecordCenter::class);$r=$center->read(['dataset'=>'intents'],2);
        self::assertSame('0.246913578024691357802468',$r['totals']['amount_umi']);
        $export=$center->export(['dataset'=>'intents'],2);$csv=stream_get_contents($export['stream']);fclose($export['stream']);
        self::assertStringContainsString($value,$csv);
    }

    public function test_network_reuses_deepro_mapping_and_spot_funding_does_not_require_chain_deposits(): void
    {
        config(['umi.asset.deposits_enabled'=>false]);
        app()->forgetInstance(FundedReadiness::class);
        app()->instance(CustodyService::class,new class extends CustodyService {
            public function __construct() {}
            public function hotSender(string $chain): string {return '0x2222222222222222222222222222222222222222';}
            public function network(string $chain): object {return (object)['chain'=>'bnb'];}
        });
        DB::table('umi_v2_live_settings')->where('id',1)->update(['umi_network_id'=>null,'dedicated_address'=>'0x2222222222222222222222222222222222222222']);
        $gate=app(FundedReadiness::class);
        self::assertSame(6,$gate->settings()->umi_network_id);
        self::assertSame([], $gate->report()['issues']);
        $candidate=(array)DB::table('umi_v2_live_settings')->find(1);
        $candidate['umi_network_id']=999;
        app(FundedConfiguration::class)->save($candidate+['revision'=>app(FundedConfiguration::class)->revision()],'reuse-network',1);
        self::assertSame(6,(int)DB::table('umi_v2_live_settings')->find(1)->umi_network_id);
        DB::table('umi_v2_live_settings')->where('id',1)->update(['intake_enabled'=>false]);
        self::assertTrue($gate->report()['ready']);
        self::assertFalse(app(\App\Services\Umi\V2\FundedDashboard::class)->member(2)['operations']['intake']);
        try {$gate->require('intake');self::fail('Per-operation switch required');}
        catch (\DomainException $e) {self::assertStringContainsString('暂未开放',$e->getMessage());}
        DB::table('networks')->where('id',6)->update(['status'=>false]);
        self::assertFalse($gate->report()['ready']);
        self::assertNull($gate->settings()->umi_network_id);
    }

    public function test_postgres_concurrent_spot_intake_cannot_duplicate_or_overdraw(): void
    {
        if (DB::getDriverName()!=='pgsql' || !function_exists('pcntl_fork')) self::markTestSkipped('Isolated PostgreSQL required');
        $this->rootMember(2,'concurrent-spot');
        $same=$this->parallelExports([['100','same-spot'],['100','same-spot']],true);
        self::assertSame($same[0]['id'],$same[1]['id']);
        self::assertSame(1,DB::table('umi_v2_live_wallet_moves')->count());
        DB::table('wallets')->where('user_id',2)->where('currency_id',1)->update(['balance_in_trade'=>'110']);
        $other=$this->parallelExports([['100','spot-a'],['100','spot-b']],true);
        self::assertSame(1,count(array_filter($other,fn($r)=>isset($r['id']))));
        self::assertSame(1,count(array_filter($other,fn($r)=>($r['error']??'')==='UMI 余额不足。')));
        self::assertSame('10',Decimal::display((string)DB::table('wallets')->where('user_id',2)->where('currency_id',1)->value('balance_in_trade')));
        self::assertSame(2,DB::table('umi_v2_live_wallet_moves')->count());
    }

    private function spotFill(string $id, string $price, \DateTimeInterface $at, string $domain='real', array $fields=[]): int
    {
        DB::table('order_histories')->insert(['id'=>$id,'market_id'=>2,'user_id'=>2,'settlement_domain'=>$domain]);
        return DB::table('transactions')->insertGetId(array_replace(['order_id'=>$id,'market_id'=>2,'user_id'=>2,
            'price'=>$price,'base_currency'=>'1','created_at'=>\Carbon\CarbonImmutable::instance($at)->setTimezone(config('app.timezone'))->format('Y-m-d H:i:s')],$fields));
    }

    public function test_spot_quotes_exclude_non_real_trades_and_are_auditable_at_event_time(): void
    {
        $at=now()->startOfSecond();
        $this->spotFill('valid','1.5',$at->copy()->subSecond());
        foreach ([['virtual',[]],['real',['is_maker'=>true]],['real',['is_volume'=>1]],['real',['base_currency'=>'0']]] as $i=>[$domain,$fields])
            $this->spotFill('invalid'.$i,'99',$at,$domain,$fields);
        $this->spotFill('future','100',$at->copy()->addMinute());
        $service=app(\App\Services\Umi\V2\SpotQuote::class);
        $q=$service->read($at->copy()->subSecond()->toIso8601String(),true);
        self::assertSame('1.5',Decimal::display((string)$q->price));
        self::assertSame('deepro_spot',$q->source); self::assertNull($q->approved_by);
        self::assertSame($q->id,$service->read($at->copy()->subSecond()->toIso8601String(),true)->id);
        $this->spotFill('later','2',$at->copy()->addSeconds(30));
        self::assertSame('1.5',Decimal::display((string)$service->read($at->copy()->subSecond()->toIso8601String())->price));
        DB::table('transactions')->delete();
        $this->spotFill('stale','3',$at->copy()->subSeconds(301));
        try {$service->read($at->toIso8601String());self::fail('Stale/manual fallback forbidden');}
        catch (\DomainException $e) {self::assertStringContainsString('5 分钟',$e->getMessage());}
        try {app(FundedConfiguration::class)->approveQuote('UMI_USDT','1','manual','manual',now()->toIso8601String(),1);self::fail('Manual UMI forbidden');}
        catch (\DomainException $e) {self::assertStringContainsString('盘口卖一价',$e->getMessage());}
    }

    public function test_spot_move_preserves_frozen_and_funding_balances_and_records_buckets(): void
    {
        $member=$this->rootMember(2,'spot-buckets');
        DB::table('wallets')->where('user_id',2)->where('currency_id',1)->update(['balance_in_wallet'=>'50','balance_in_order'=>'70']);
        $this->intake->create(2,'spot','100','spot-join');
        $w=DB::table('wallets')->where('user_id',2)->where('currency_id',1)->first();
        self::assertSame('10',Decimal::display((string)$w->balance_in_trade));
        self::assertSame('50',Decimal::display((string)$w->balance_in_wallet));
        self::assertSame('70',Decimal::display((string)$w->balance_in_order));
        $move=DB::table('umi_v2_live_wallet_moves')->first();
        self::assertSame('trade',$move->from_bucket);self::assertSame('wallet',$move->to_bucket);
        $receipt=app(\App\Services\Umi\V2\RecordCenter::class)->read(['dataset'=>'wallet_moves'],2);
        self::assertSame(1,$receipt['total']);
        self::assertSame(0,app(\App\Services\Umi\V2\RecordCenter::class)->read(['dataset'=>'wallet_moves'],3)['total']);
        try {DB::transaction(fn()=>app(FundedWallet::class)->move(2,1,'100','intake:spot-join','activation_intake',$move->source_ref,$member->id,'wallet','wallet'));self::fail('Replay bucket mismatch');}
        catch (\DomainException) {self::assertSame(1,DB::table('umi_v2_live_wallet_moves')->count());}
    }

    public function test_settlement_rejects_open_days_and_cannot_skip_an_earlier_day(): void
    {
        $m=$this->rootMember(2,'calendar');$this->seedActiveCycle($m,'100');
        $day=now('Asia/Shanghai')->toDateString();$next=now('Asia/Shanghai')->addDay()->toDateString();
        try {$this->settlement->runDay($day,'0.01',1);self::fail('Open day must reject');} catch (\DomainException) {}
        self::assertSame(0,DB::table('umi_v2_live_daily_runs')->count());$this->travelTo(now('Asia/Shanghai')->addDays(3));
        try {$this->settlement->runDay($next,'0.01',1);self::fail('Skipped day must reject');} catch (\DomainException) {}
        self::assertSame($day,app(\App\Services\Umi\V2\FundedCalendar::class)->nextDay());$this->settlement->runDay($day,'0.01',1);
        self::assertSame($next,app(\App\Services\Umi\V2\FundedCalendar::class)->nextDay());
        self::assertSame('1',Decimal::display((string)DB::table('umi_v2_release_events')->sum('released_umi')));
        self::assertTrue($this->settlement->runDay($day,'0.01',1)['replayed']);
    }

    public function test_configuration_rate_is_effective_next_day_and_rejects_stale_revision(): void
    {
        $service=app(FundedConfiguration::class);$revision=$service->revision();$candidate=(array)DB::table('umi_v2_live_settings')->find(1);
        $candidate['settlement_rate']='0.009';$candidate['revision']=$revision;$service->save($candidate,'rate-next-day',1);
        $calendar=app(\App\Services\Umi\V2\FundedCalendar::class);
        self::assertSame('0.01',Decimal::display($calendar->rate(now('Asia/Shanghai')->toDateString())));
        self::assertSame('0.009',Decimal::display($calendar->rate(now('Asia/Shanghai')->addDay()->toDateString())));
        try {$service->save($candidate,'rate-stale-editor',1);self::fail('Stale editor must reject');} catch (\DomainException) {}
        self::assertSame(1,DB::table('umi_v2_live_settings_audit')->count());
    }

    public function test_pool_cannot_change_after_funded_intake_and_cancel_is_exactly_once(): void
    {
        $this->rootMember(2,'cancel');$i=$this->intake->create(2,'spot','100','cancel-join');
        $service=app(FundedConfiguration::class);$candidate=(array)DB::table('umi_v2_live_settings')->find(1);
        $candidate['pool_user_id']=3;$candidate['revision']=$service->revision();
        try {$service->save($candidate,'switch-funded-pool',1);self::fail('Pool switch must reject');} catch (\DomainException) {}
        self::assertSame(1,(int)DB::table('umi_v2_live_settings')->find(1)->pool_user_id);
        $cancel=app(\App\Services\Umi\V2\FundedCancellation::class);
        self::assertFalse($cancel->cancelIntake($i->id,1,'用户撤回尚未提交托管的参与申请')['replayed']);
        self::assertTrue($cancel->cancelIntake($i->id,1,'用户撤回尚未提交托管的参与申请')['replayed']);
        self::assertSame('110',Decimal::display(app(FundedWallet::class)->balance(2,'trade')));self::assertSame('10000',Decimal::display(app(FundedWallet::class)->balance(1)));
        self::assertSame('cancelled',DB::table('umi_v2_cycles')->find($i->cycle_id)->status);self::assertSame(1,DB::table('umi_v2_recovery_actions')->count());
        self::assertSame('0',app(\App\Services\Umi\V2\FundedDashboard::class)->burnSummary()['pending_umi']);
    }

    public function test_cancellation_rejects_any_custody_reference_without_refund(): void
    {
        $this->rootMember(2,'custody-cancel');$i=$this->intake->create(2,'spot','100','custody-cancel-join');
        DB::table('umi_v2_live_burn_lots')->where('cycle_id',$i->cycle_id)->update(['custody_transfer_id'=>123]);
        try {app(\App\Services\Umi\V2\FundedCancellation::class)->cancelIntake($i->id,1,'核对等待托管中的参与申请');self::fail('Custody reference prohibits refund');}catch(\DomainException){}
        self::assertSame('10',Decimal::display(app(FundedWallet::class)->balance(2,'trade')));self::assertSame(0,DB::table('umi_v2_recovery_actions')->count());
    }

    public function test_recovery_waits_for_original_historical_quote_then_credits_once(): void
    {
        // Financial timestamp columns have second precision in PostgreSQL.
        $this->travelTo(now()->addSeconds(2)->startOfSecond());
        $m=$this->rootMember(2,'recovery');$this->seedActiveCycle($m,'100');$this->settleCompleteDay(now('Asia/Shanghai')->toDateString(),'0.01',1);
        $cycle=DB::table('umi_v2_cycles')->where('member_id',$m->id)->first();$this->withdrawal->transfer(2,$cycle->id,'static','0.8','recovery-income');$w=$this->withdrawal->request(2,'0.8','recovery-withdraw');
        DB::table('umi_v2_live_quotes')->where('asset','HK08379_USDT')->delete();
        $lot=(int)DB::table('umi_v2_live_burn_lots')->where('withdrawal_id',$w->id)->value('id');$this->finalizeLot($lot);
        $service=app(\App\Services\Umi\V2\FundedRecovery::class);$service->discover();$service->discover();
        self::assertSame(1,DB::table('umi_v2_recovery_tasks')->count());$id=(int)DB::table('umi_v2_recovery_tasks')->value('id');
        self::assertFalse($service->retry($id,1)['resolved']);self::assertSame(0,DB::table('umi_v2_stock_point_entries')->count());
        $at=DB::table('umi_v2_live_burn_lots')->find($lot)->confirmed_at;
        app(FundedConfiguration::class)->approveQuote('HK08379_USDT','0.1','fixture-restored','historical-proof',$at,1);
        self::assertTrue($service->retry($id,1)['resolved']);self::assertTrue($service->retry($id,1)['replayed']);
        self::assertSame(1,DB::table('umi_v2_stock_point_entries')->count());self::assertSame('resolved',DB::table('umi_v2_recovery_tasks')->find($id)->status);
        self::assertSame(2,DB::table('umi_v2_recovery_actions')->count());
    }

    public function test_rank_recalculation_ignores_future_cycle_and_preserves_audit_basis(): void
    {
        $m=$this->rootMember(2,'future-rank');$this->seedActiveCycle($m,'100');
        DB::table('umi_v2_cycles')->where('member_id',$m->id)->update(['starts_on'=>now('Asia/Shanghai')->addDay()->toDateString()]);DB::table('umi_v2_members')->where('id',$m->id)->update(['level'=>1]);
        $method=new \ReflectionMethod($this->settlement,'refreshRanks');$method->setAccessible(true);$method->invoke($this->settlement,now('Asia/Shanghai')->toDateString(),1);
        self::assertSame(0,(int)DB::table('umi_v2_members')->find($m->id)->level);$audit=DB::table('umi_v2_rank_audit')->first();
        self::assertSame('0',Decimal::display((string)$audit->personal_usdt));self::assertSame(64,strlen($audit->basis_sha256));self::assertSame(now('Asia/Shanghai')->toDateString(),$audit->business_date);
    }

    public function test_existing_v2_membership_blocks_late_legacy_binding_without_reparenting(): void
    {
        Schema::table('users', static function(Blueprint $t):void {
            $t->string('email')->nullable(); $t->timestamp('email_verified_at')->nullable();
            $t->unsignedBigInteger('referral_id')->nullable(); $t->string('referral_code')->nullable();
        });
        DB::table('users')->where('id',2)->update(['email'=>'audit-member@example.invalid',
            'email_verified_at'=>now(), 'referral_id'=>1,'referral_code'=>'DP-AUDIT-ONLY']);
        Schema::create('umi_business_state',static function(Blueprint $t):void {$t->id();});
        DB::table('umi_business_state')->insert(['id'=>1]);
        Schema::create('umi_business_accounts',static function(Blueprint $t):void {
            $t->id();$t->unsignedBigInteger('user_id')->nullable();$t->unsignedBigInteger('legacy_id')->nullable();
            $t->unsignedBigInteger('parent_id')->nullable();$t->unsignedBigInteger('legacy_parent_id')->nullable();
            $t->string('code');$t->boolean('fixture')->default(false);$t->timestamps();
        });
        Schema::create('umi_legacy_accounts',static function(Blueprint $t):void {
            $t->unsignedBigInteger('legacy_id')->primary();$t->unsignedBigInteger('user_id')->nullable();
            $t->unsignedBigInteger('parent_legacy_id')->nullable();$t->string('activation_status')->default('pending');
            $t->integer('legacy_status')->default(2);$t->text('approved_email')->nullable();
            $t->timestamp('activated_at')->nullable();$t->timestamps();
        });
        Schema::create('umi_binding_challenges',static function(Blueprint $t):void {
            $t->string('id')->primary();$t->unsignedBigInteger('legacy_id');$t->unsignedBigInteger('user_id');
            $t->string('email_lookup');$t->string('code_hash');$t->integer('attempts')->default(0);
            $t->timestamp('expires_at');$t->timestamp('used_at')->nullable();$t->timestamp('created_at');
        });
        $oldParent = $this->rootMember(1,'audit-old-parent');
        $newParent = $this->rootMember(3,'audit-new-parent');
        $member = app(MemberEnrollment::class)->enroll(2,$newParent->member_code,'audit-new-enroll');
        self::assertSame((int)$newParent->id,(int)DB::table('umi_v2_sponsor_edges')
            ->where('child_member_id',$member->id)->value('parent_member_id'));

        \App\Models\Umi\LegacyAccount::create(['legacy_id'=>100,'user_id'=>1,
            'parent_legacy_id'=>null,'activation_status'=>'activated']);
        \App\Models\Umi\LegacyAccount::create(['legacy_id'=>200,'user_id'=>null,
            'parent_legacy_id'=>100,'activation_status'=>'pending','approved_email'=>'audit-member@example.invalid']);
        DB::table('umi_business_accounts')->insert([
            ['id'=>100,'user_id'=>1,'legacy_id'=>100,'parent_id'=>null,'legacy_parent_id'=>null,'code'=>'OLD-100'],
            ['id'=>200,'user_id'=>null,'legacy_id'=>200,'parent_id'=>100,'legacy_parent_id'=>100,'code'=>'OLD-200'],
        ]);
        $user=\App\Models\User\User::findOrFail(2);
        $challenge='77777777-7777-4777-8777-777777777777';
        DB::table('umi_binding_challenges')->insert(['id'=>$challenge,'legacy_id'=>200,'user_id'=>2,
            'email_lookup'=>\App\Services\Umi\LegacyActivation::lookup($user->email),
            'code_hash'=>\Illuminate\Support\Facades\Hash::make('12345678'),
            'expires_at'=>now()->addMinutes(10),'created_at'=>now()]);
        try {app(\App\Services\Umi\LegacyBinding::class)->complete($user,$challenge,'12345678');self::fail('Late binding must be reviewed');}catch(\Illuminate\Validation\ValidationException){}
        self::assertNull(DB::table('umi_legacy_accounts')->where('legacy_id',200)->value('user_id'));
        self::assertNull(DB::table('umi_business_accounts')->where('id',200)->value('user_id'));
        $again=app(MemberEnrollment::class)->enroll(2,null,'audit-after-binding');
        self::assertSame((int)$member->id,(int)$again->id);
        self::assertSame((int)$newParent->id,(int)DB::table('umi_v2_sponsor_edges')
            ->where('child_member_id',$member->id)->value('parent_member_id'),
            'Rejected binding preserves the existing member tree and leaves the old account unclaimed.');
        self::assertNotSame((int)$oldParent->id,(int)DB::table('umi_v2_sponsor_edges')
            ->where('child_member_id',$member->id)->value('parent_member_id'));
        self::assertNull(DB::table('umi_v2_members')->where('id',$member->id)->value('legacy_identity_ref'));
        self::assertSame(1,(int)DB::table('users')->where('id',2)->value('referral_id'),
            'Independent exchange referral was not changed by UMI enrollment/binding.');
    }

    public function test_invalid_business_amounts_return_422_without_mutation(): void
    {
        $this->rootMember(2,'audit-input-member');
        $this->withoutMiddleware();
        \Illuminate\Support\Facades\Route::post('/__audit/umi/preview',static function(\Illuminate\Http\Request $r) {
            $r->setUserResolver(static fn()=>\App\Models\User\User::findOrFail(2));
            return app(\App\Http\Controllers\Web\Client\UmiV2Controller::class)->preview($r);
        });
        $before=DB::table('wallets')->where('user_id',2)->where('currency_id',1)->value('balance_in_trade');
        foreach (['0','1'] as $amount) {
            $response=$this->postJson('/__audit/umi/preview',['action'=>'activate','amount'=>$amount]);
            self::assertSame(422,$response->getStatusCode(),
                'Invalid business input is a validation response.');
        }
        self::assertSame(0,DB::table('umi_v2_live_intents')->count());
        self::assertSame(0,DB::table('umi_v2_live_wallet_moves')->count());
        self::assertEquals($before,DB::table('wallets')->where('user_id',2)->where('currency_id',1)->value('balance_in_trade'));
    }

    public function test_deposit_hold_blocks_new_intake_but_does_not_hide_a_successful_receipt(): void
    {
        $this->rootMember(2,'risk-replay');$intent=$this->intake->create(2,'spot','100','risk-existing');$deposit=$this->creditedDeposit(2,'1');
        DB::table('deposit_review_events')->insert(['deposit_id'=>$deposit,'reason'=>'DEPOSIT_POST_CREDIT_CHAIN_CONFLICT','status'=>'open']);
        self::assertSame($intent->id,$this->intake->create(2,'spot','100','risk-existing')->id);
        try{$this->intake->create(2,'spot','100','risk-new');self::fail('Open risk hold must block new funding');}catch(\Illuminate\Validation\ValidationException){}
        self::assertSame(1,DB::table('umi_v2_live_intents')->count());self::assertSame('10',Decimal::display(app(FundedWallet::class)->balance(2,'trade')));
    }

    public function test_cutover_rejects_old_quote_and_same_day_start_without_partial_migration(): void
    {
        $this->travelTo(now()->addSeconds(2)->startOfSecond());
        $this->cutoverFixture();
        DB::table('umi_v2_live_settings')->where('id',1)->update(['account_cutover_enabled'=>true]);
        $service=app(\App\Services\Umi\V2\AccountCutover::class);$preview=$service->preview();
        $quote=app(FundedConfiguration::class)->quote('UMI_USDT');
        DB::table('umi_v2_live_quotes')->where('id',$quote->id)->update(['observed_at'=>\App\Services\Umi\V2\FundedTime::database(now()->subMinutes(6))]);
        try {$service->run($preview['sha256'],$quote->id,now('Asia/Shanghai')->addDay()->toDateString(),1,1);self::fail('Old capture price must reject');}
        catch(\DomainException $e){self::assertStringContainsString('报价凭证',$e->getMessage());}
        DB::table('umi_v2_live_quotes')->where('id',$quote->id)->update(['observed_at'=>$preview['approval']->cutoff_at]);
        try {$service->run($preview['sha256'],$quote->id,now('Asia/Shanghai')->toDateString(),1,1);self::fail('Same-day migration must reject');}
        catch(\DomainException $e){self::assertStringContainsString('截止业务日之后',$e->getMessage());}
        self::assertSame(0,DB::table('umi_v2_account_merges')->count());
        self::assertSame('145',Decimal::display((string)DB::table('umi_business_balances')->where('bucket','like','account:%')->sum('amount')));
    }

    public function test_cancelling_pending_principal_topup_restores_each_source_once(): void
    {
        $this->cutoverFixture();DB::table('umi_v2_live_settings')->where('id',1)->update(['account_cutover_enabled'=>true]);
        $service=app(\App\Services\Umi\V2\AccountCutover::class);$preview=$service->preview();
        $service->run($preview['sha256'],app(FundedConfiguration::class)->quote('UMI_USDT')->id,now('Asia/Shanghai')->addDay()->toDateString(),1,1);
        $i=$this->intake->create(3,'spot','80','pending-cancel-topup');
        $cancel=app(\App\Services\Umi\V2\FundedCancellation::class);
        $result=$cancel->cancelIntake($i->id,1,'核对新划入与历史待激活本金分别退回');
        self::assertSame('20',Decimal::display($result['restored_principal_umi']));
        self::assertSame('80',Decimal::display($result['refunded_spot_umi']));
        self::assertTrue($cancel->cancelIntake($i->id,1,'核对新划入与历史待激活本金分别退回')['replayed']);
        self::assertSame('20',Decimal::display((string)DB::table('umi_v2_pending_principals')->value('amount_umi')));
        self::assertSame('100',Decimal::display(app(FundedWallet::class)->balance(3,'trade')));
        self::assertSame('10000',Decimal::display(app(FundedWallet::class)->balance(1)));
        self::assertSame(1,DB::table('umi_v2_recovery_actions')->count());
    }

    public function test_stock_pool_coverage_counts_locked_units_before_enabling_export(): void
    {
        $member=$this->rootMember(2,'coverage');
        DB::table('umi_v2_stock_share_accounts')->insert(['member_id'=>$member->id,'locked_shares'=>'4','available_shares'=>'2',
            'created_at'=>\App\Services\Umi\V2\FundedTime::database(now()),'updated_at'=>\App\Services\Umi\V2\FundedTime::database(now())]);
        $service=app(StockShares::class);$coverage=$service->coverage(1);
        self::assertSame('6',Decimal::display($coverage['total_obligation']));
        self::assertSame('1',Decimal::display($coverage['shortfall']));
        self::assertSame('internal_product_units_only',$coverage['scope']);
        try {$service->assertPoolAvailable(1);self::fail('Partial coverage must block enabling');}
        catch(\DomainException $e){self::assertStringContainsString('尚未覆盖',$e->getMessage());}
        DB::table('wallets')->where('user_id',1)->where('currency_id',3)->update(['balance_in_trade'=>'6']);
        $service->assertPoolAvailable(1);
        self::assertSame('0',Decimal::display($service->coverage(1)['shortfall']));
    }

    private function cutoverFixture(): void
    {
        // The capture cutoff and contemporaneous order-book quote describe one instant.
        $this->freezeTime();
        Schema::create('umi_business_accounts',static function(Blueprint $t): void {
            $t->id();$t->unsignedBigInteger('user_id')->nullable();$t->unsignedBigInteger('legacy_id')->nullable();
            $t->unsignedBigInteger('parent_id')->nullable();$t->unsignedBigInteger('legacy_parent_id')->nullable();
            $t->string('code');$t->boolean('fixture')->default(false);
        });
        Schema::create('umi_business_balances',static function(Blueprint $t): void {
            $t->string('bucket');$t->string('asset');$t->decimal('amount',54,24);$t->primary(['bucket','asset']);
        });
        Schema::create('umi_business_entries',static function(Blueprint $t): void {
            $t->id();$t->uuid('operation_id');$t->string('bucket');$t->string('asset');$t->decimal('delta',54,24);
            $t->decimal('balance_after',54,24);$t->string('description');$t->dateTimeTz('created_at');
        });
        Schema::create('umi_business_state',static function(Blueprint $t): void {$t->id();$t->integer('rule_id');$t->date('business_date');});
        Schema::create('umi_business_operations',static function(Blueprint $t): void {
            $t->uuid('id')->primary();$t->string('scope');$t->string('request_key');$t->string('request_hash');$t->string('type');
            $t->integer('actor_id');$t->integer('account_id');$t->integer('rule_id');$t->date('business_date');$t->json('details');$t->dateTimeTz('created_at');
        });
        Schema::create('umi_business_plans',static function(Blueprint $t): void {$t->id();$t->string('status');});
        Schema::create('umi_business_unstakes',static function(Blueprint $t): void {$t->id();$t->dateTimeTz('completed_at')->nullable();});
        DB::table('umi_business_state')->insert(['id'=>1,'rule_id'=>1,'business_date'=>now()->toDateString()]);
        DB::table('umi_business_accounts')->insert([['id'=>1,'legacy_id'=>101,'user_id'=>2,'code'=>'old-a','parent_id'=>null],['id'=>2,'legacy_id'=>102,'user_id'=>3,'code'=>'old-b','parent_id'=>1]]);
        foreach ([[1,'main','100'],[1,'treasure','25'],[2,'reserve','20']] as [$id,$pocket,$amount])
            DB::table('umi_business_balances')->insert(['bucket'=>"account:$id:$pocket",'asset'=>'UMI','amount'=>$amount]);
        $capture=['manifest_sha256'=>str_repeat('c',64),'source'=>'https://fixture.invalid',
            'started_at'=>now()->toIso8601String(),'finished_at'=>now()->toIso8601String(),
            'principal_umi'=>'145','payload'=>['fixture'=>true],'accounts'=>[
                ['legacy_id'=>101,'principal_umi'=>'125','balances'=>['main'=>'100','treasure'=>'25'],
                    'source'=>['resources'=>['detail'=>['inviter'=>null]]]],
                ['legacy_id'=>102,'principal_umi'=>'20','balances'=>['reserve'=>'20'],
                    'source'=>['resources'=>['detail'=>['inviter'=>['user_id'=>101]]]]],
            ]];
        $snapshots=app(\App\Services\Umi\V2\CaptureSnapshots::class);
        $batch=$snapshots->stage($capture,1);
        $preview=app(\App\Services\Umi\V2\AccountCutover::class)->preview((int)$batch->id);
        $snapshots->approve((int)$batch->id,$preview['sha256'],now()->toIso8601String(),'隔离测试核定原始快照和本地余额完全一致',2);
    }

    private function parallelExports(array $requests, bool $spot = false): array
    {
        DB::disconnect(); $children = []; $paths = [];
        foreach ($requests as [$amount, $key]) {
            $path = tempnam(sys_get_temp_dir(), 'umi-export-test-'); $paths[] = $path;
            $pid = pcntl_fork();
            if ($pid === -1) { throw new \RuntimeException('Unable to fork test worker'); }
            if ($pid === 0) {
                DB::purge(); usleep(100000);
                try { $move = $spot ? $this->intake->create(2,'spot',$amount,$key) : app(StockShares::class)->toTradingWallet(2, $amount, $key); $result = ['id'=>$move->id]; }
                catch (\Throwable $e) { $result = ['error'=>$e->getMessage()]; }
                file_put_contents($path, json_encode($result)); DB::disconnect(); exit(0);
            }
            $children[] = $pid;
        }
        foreach ($children as $pid) { pcntl_waitpid($pid, $status); self::assertSame(0, pcntl_wexitstatus($status)); }
        DB::purge(); $results = [];
        foreach ($paths as $path) { $results[] = json_decode(file_get_contents($path), true, 512, JSON_THROW_ON_ERROR); unlink($path); }
        return $results;
    }

    private function settleCompleteDay(string $day,string $rate,int $actor): array
    {
        $previous=\Carbon\Carbon::getTestNow();$immutable=\Carbon\CarbonImmutable::getTestNow();
        $at=\Carbon\CarbonImmutable::parse($day,'Asia/Shanghai')->addDay()->startOfDay();
        \Carbon\Carbon::setTestNow($at);\Carbon\CarbonImmutable::setTestNow($at);
        try { return $this->settlement->runDay($day,$rate,$actor); }
        finally { \Carbon\Carbon::setTestNow($previous);\Carbon\CarbonImmutable::setTestNow($immutable); }
    }

    private function rootMember(int $userId, string $key): object
    {
        $existing = DB::table('umi_v2_members')->where('user_id', $userId)->first();
        if ($existing) { return $existing; }
        $id = DB::table('umi_v2_members')->insertGetId([
            'user_id' => $userId, 'member_code' => 'UTEST' . $userId,
            'status' => 'active', 'level' => 0, 'joined_at' => \App\Services\Umi\V2\FundedTime::database(now()),
            'created_at' => \App\Services\Umi\V2\FundedTime::database(now()), 'updated_at' => \App\Services\Umi\V2\FundedTime::database(now()),
        ]);
        return DB::table('umi_v2_members')->find($id);
    }

    private function seedActiveCycle(object $member, string $amount): void
    {
        $policy = (int) $this->intake->policy()->id;
        $opening = Cycle::open('1', (string) $member->id, $amount, '1', (string) $policy);
        $id = DB::table('umi_v2_cycles')->insertGetId([
            'member_id' => $member->id, 'policy_version_id' => $policy,
            'cycle_number' => 1,
            'activation_request_key' => 'live:peer:' . $member->id,
            'multiplier' => $opening->multiple,
            'principal_umi' => $opening->principal,
            'usd_quote_per_umi' => $opening->priceUsdt,
            'principal_usd' => $opening->valueUsdt,
            'cap_umi' => $opening->quota, 'status' => 'active',
            'starts_on' => now('Asia/Shanghai')->toDateString(),
            'created_at' => \App\Services\Umi\V2\FundedTime::database(now()), 'updated_at' => \App\Services\Umi\V2\FundedTime::database(now()),
        ]);
        DB::table('umi_v2_live_intents')->insert([
            'member_id' => $member->id,
            'request_key' => 'peer-intent:' . $id,
            'source' => 'wallet', 'status' => 'active',
            'amount_umi' => $amount, 'umi_usd_quote' => '1',
            'quote_source' => 'fixture:peer', 'quote_at' => \App\Services\Umi\V2\FundedTime::database(now()),
            'cycle_id' => $id, 'created_at' => \App\Services\Umi\V2\FundedTime::database(now()), 'updated_at' => \App\Services\Umi\V2\FundedTime::database(now()),
        ]);
    }

    private function creditedDeposit(int $userId, string $amount): int
    {
        $now = now()->addSecond();
        $id = DB::table('deposits')->insertGetId([
            'user_id' => $userId, 'currency_id' => 1, 'network_id' => 6,
            'source_id' => 'verified:56:' . uniqid('', true),
            'status' => DEPOSIT_CONFIRMED, 'amount' => $amount,
            'created_at' => \App\Services\Umi\V2\FundedTime::database($now), 'updated_at' => \App\Services\Umi\V2\FundedTime::database($now),
        ]);
        DB::table('chain_deposit_receipts')->insert([
            'deposit_id' => $id, 'credited_amount' => $amount,
            'evidence' => json_encode(['chain' => ['timestamp' => $now->getTimestamp() * 1000]]),
            'created_at' => \App\Services\Umi\V2\FundedTime::database($now),
        ]);
        $wallet = DB::table('wallets')->where('user_id', $userId)->where('currency_id', 1)->first();
        DB::table('wallets')->where('id', $wallet->id)->update([
            'balance_in_wallet' => Decimal::add((string) $wallet->balance_in_wallet, $amount),
        ]);
        return $id;
    }

    private function finalizeLot(int $lotId): void
    {
        $lot = DB::table('umi_v2_live_burn_lots')->find($lotId);
        $hash = '0x' . str_pad(dechex($lotId), 64, '0', STR_PAD_LEFT);
        $transferId = DB::table('custody_transfers')->insertGetId([
            'purpose' => 'umi_v2_burn', 'status' => 'completed', 'txn' => $hash,
            'sender' => '0x2222222222222222222222222222222222222222',
            'destination' => FundedReadiness::DEAD,
            'contract' => config('umi.asset.contract'),
            'amount' => $lot->amount_umi, 'confirmations' => 15,
        ]);
        DB::table('umi_v2_live_burn_lots')->where('id', $lotId)->update([
            'custody_transfer_id' => $transferId, 'status' => 'custody',
        ]);
        $this->burn->synchronize($lotId);
    }
}
