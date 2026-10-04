<?php

namespace App\Services\Umi\V2;

use App\Domain\Umi\V2\Decimal;

use DomainException;
use Illuminate\Support\Facades\DB;

final class FundedConfiguration
{
    public function revision(?object $settings = null): string
    {
        $s=$settings ?? DB::table('umi_v2_live_settings')->where('id',1)->first();
        $values=[]; foreach (['pool_user_id','dedicated_address','intake_enabled','settlement_enabled','withdrawal_enabled','account_cutover_enabled','stock_transfer_enabled','settlement_rate','updated_at'] as $field) $values[$field]=(string)($s->{$field}??'');
        return hash('sha256',json_encode($values,JSON_THROW_ON_ERROR));
    }

    public function save(array $input, string $key, int $actorId): array
    {
        $address = strtolower(trim((string) ($input['dedicated_address'] ?? '')));
        if ($address !== '' && (!preg_match('/^0x[a-f0-9]{40}$/D', $address)
            || in_array($address, ['0x' . str_repeat('0', 40), FundedReadiness::DEAD,
                strtolower((string) config('umi.asset.contract'))], true))) {
            throw new DomainException('专属地址无效。');
        }
        $rate=Decimal::rate((string)($input['settlement_rate']??'0.01'));
        if (Decimal::cmp($rate,'0.008')<0 || Decimal::cmp($rate,'0.015')>0) throw new DomainException('静态日率须为 0.008 至 0.015。');
        try { $networkId = app(FundedWallet::class)->networkId(); }
        catch (DomainException) { $networkId = null; }
        $candidate = [
            'settlement_rate' => $rate,
            'pool_user_id' => $input['pool_user_id'] ?: null,
            'umi_network_id' => $networkId,
            'dedicated_address' => $address ?: null,
            'intake_enabled' => (bool) $input['intake_enabled'],
            'settlement_enabled' => (bool) $input['settlement_enabled'],
            'withdrawal_enabled' => (bool) $input['withdrawal_enabled'],
            'account_cutover_enabled' => (bool) ($input['account_cutover_enabled'] ?? false),
            'stock_transfer_enabled' => (bool) ($input['stock_transfer_enabled'] ?? false),
        ];
        return DB::transaction(function () use ($candidate, $key, $actorId, $input): array {
            $current = DB::table('umi_v2_live_settings')->where('id', 1)->lockForUpdate()->first()
                ?? throw new DomainException('UMI 设置尚未建立。');
            $replay = DB::table('umi_v2_live_settings_audit')->where('request_key', $key)->first();
            if ($replay) {
                $saved = json_decode((string) $replay->after_json, true, 512, JSON_THROW_ON_ERROR);
                if ($saved !== $candidate) { throw new DomainException('设置编号已用于其他修改。'); }
                return $saved + ['replayed' => true];
            }
            if (!isset($input['revision']) || !hash_equals($this->revision($current),(string)$input['revision'])) {
                throw new DomainException('UMI 配置已变化，请刷新后重新核对。');
            }
            $poolChanged=(int)$current->pool_user_id!==(int)$candidate['pool_user_id'];
            $addressChanged=strtolower((string)$current->dedicated_address)!==strtolower((string)$candidate['dedicated_address']);
            if (($poolChanged || $addressChanged) && (DB::table('umi_v2_live_wallet_moves')->exists()
                || DB::table('umi_v2_live_intents')->exists() || DB::table('umi_v2_account_merges')->exists()
                || DB::table('umi_v2_stock_point_entries')->exists())) {
                throw new DomainException('已有资金或权益记录，不能直接变更资金池或销毁发送地址；须另行核对存量义务迁移。');
            }
            $before = [];
            foreach (array_keys($candidate) as $field) { $before[$field] = $current->{$field}; }
            if ($candidate['pool_user_id'] !== null) {
                $pool = DB::table('users')->where('id', $candidate['pool_user_id'])->first();
                if (!$pool || (bool) ($pool->is_xn ?? false) || (bool) ($pool->is_xm ?? false)
                    || (bool) ($pool->deleted ?? false) || (bool) ($pool->deactivated ?? false)) {
                    throw new DomainException('资金池账户无效。');
                }
            }
            if ($candidate['intake_enabled'] || $candidate['settlement_enabled'] || $candidate['withdrawal_enabled']) {
                $report = app(FundedReadiness::class)->report((object) $candidate);
                if (!$report['ready']) {
                    throw new DomainException('充值、结算或提现尚未具备启用条件，请先完成页面列出的配置。');
                }
            }
            if ($candidate['stock_transfer_enabled']) {
                app(StockShares::class)->assertPoolAvailable((int) $candidate['pool_user_id']);
                if (!app(StockShares::class)->audit()['ok']) {
                    throw new DomainException('股票份额对账尚未通过，暂不能启用划转。');
                }
            }
            if (Decimal::cmp((string)$current->settlement_rate,$candidate['settlement_rate'])!==0) {
                DB::table('umi_v2_rate_schedule')->updateOrInsert(['effective_on'=>now(config('umi-v2.timezone'))->addDay()->toDateString()],
                    ['static_rate'=>$candidate['settlement_rate'],'actor_id'=>$actorId,'created_at'=>FundedTime::database(now()),'updated_at'=>FundedTime::database(now())]);
            }
            DB::table('umi_v2_live_settings')->where('id', 1)->update($candidate + [
                'updated_by' => $actorId, 'updated_at' => FundedTime::database(now()),
            ]);
            DB::table('umi_v2_live_settings_audit')->insert([
                'request_key' => $key,
                'before_json' => json_encode($before, JSON_THROW_ON_ERROR),
                'after_json' => json_encode($candidate, JSON_THROW_ON_ERROR),
                'actor_id' => $actorId, 'created_at' => FundedTime::database(now()),
            ]);
            return $candidate + ['replayed' => false];
        }, 3);
    }

    public function approveQuote(string $asset, string $price, string $source,
        string $sourceRef, string $observedAt, int $actorId): object
    {
        if ($asset === 'UMI_USDT') throw new DomainException('UMI 计价自动取 Deepro 盘口卖一价，不接受手工报价。');
        if (!in_array($asset, ['UMI_USDT', 'HK08379_USDT'], true)
            || !preg_match('/^[A-Za-z0-9:_-]{1,160}$/D', $sourceRef)
            || trim($source) === '' || strlen($source) > 120) {
            throw new DomainException('报价来源无效。');
        }
        $price = Decimal::amount($price, true);
        $time = \Carbon\CarbonImmutable::parse($observedAt);
        if ($time->isFuture() || $time->lt(now()->subDays(4))) {
            throw new DomainException('报价时间无效。');
        }
        return DB::transaction(function () use ($asset, $price, $source, $sourceRef, $time, $actorId): object {
            $row = DB::table('umi_v2_live_quotes')->where('asset', $asset)
                ->where('source', $source)->where('source_ref', $sourceRef)->first();
            if ($row) {
                if (Decimal::cmp((string) $row->price, $price) !== 0 ||
                    (int) $row->approved_by !== $actorId || !\Carbon\CarbonImmutable::parse($row->observed_at)->equalTo($time)) {
                    throw new DomainException('报价编号已用于其他记录。');
                }
                return $row;
            }
            $id = DB::table('umi_v2_live_quotes')->insertGetId([
                'asset' => $asset, 'price' => $price,
                'source' => trim($source), 'source_ref' => $sourceRef,
                'observed_at' => FundedTime::database($time), 'approved_by' => $actorId,
                'created_at' => FundedTime::database(now()),
            ]);
            return DB::table('umi_v2_live_quotes')->find($id);
        }, 3);
    }

    public function quote(string $asset): object
    {
        if ($asset === 'UMI_USDT') return app(BestAskQuote::class)->read(true);
        $row = DB::table('umi_v2_live_quotes')->where('asset', $asset)
            ->whereNotNull('approved_by')->orderByDesc('observed_at')->orderByDesc('id')->first();
        $maxAge = $asset === 'UMI_USDT' ? 300 : 72 * 3600;
        if (!$row || \Carbon\CarbonImmutable::parse($row->observed_at)->lt(now()->subSeconds($maxAge))) {
            throw new DomainException('当前报价暂不可用，请稍后再试。');
        }
        return $row;
    }

    /** Historical approved quote used for a burn; later prices must never reprice points. */
    public function quoteAt(string $asset, string $time): object
    {
        if ($asset === 'UMI_USDT') return app(BestAskQuote::class)->at($time);
        $moment = \Carbon\CarbonImmutable::parse($time);
        $row = DB::table('umi_v2_live_quotes')->where('asset', $asset)
            ->whereNotNull('approved_by')->where('observed_at', '<=', FundedTime::database($moment))
            ->orderByDesc('observed_at')->orderByDesc('id')->first();
        $maxAge = $asset === 'UMI_USDT' ? 300 : 72 * 3600;
        if (!$row || \Carbon\CarbonImmutable::parse($row->observed_at)
            ->lt($moment->subSeconds($maxAge))) {
            throw new DomainException('销毁时点报价尚未核实。');
        }
        return $row;
    }
}
