<?php
namespace App\Services\Referral;

use App\Models\User\User;
use Illuminate\Support\Facades\DB;

/** Exchange fee sharing only: no UMI tables, rates, balances or ancestry. */
final class ExchangeRewards
{
    public const VERSION = 'exchange-fees-20260920-v1';
    public const RATES = [1=>15, 2=>10, 3=>8, 4=>6, 5=>4, 6=>3, 7=>2, 8=>2];

    public function record(string $business, string $sourceId, string $transactionId, int $userId, int $currencyId, string $fee, string $domain): string
    {
        if (!in_array($business, ['spot','futures_entry','futures_exit'], true) || !in_array($domain, ['real','virtual','unknown'], true)) throw new \InvalidArgumentException('Invalid referral event');
        if (!preg_match('/^[a-zA-Z0-9_-]{1,64}$/D', $sourceId) || !preg_match('/^\d{1,18}(\.\d{1,18})?$/D', $fee)) throw new \InvalidArgumentException('Invalid referral event amount or identity');
        if (!DB::transactionLevel()) throw new \LogicException('Record referral fees inside the source trade transaction');
        $key = $business.':'.$sourceId;
        // Different workers may observe the same fill; serialize by event before taking its snapshot.
        DB::select('SELECT pg_advisory_xact_lock(hashtextextended(?, 0))', ['exchange-referral:'.$key]);
        $old = DB::table('exchange_referral_events')->find($key);
        if ($old) {
            if ((int)$old->source_user_id !== $userId || (int)$old->currency_id !== $currencyId || $old->balance_domain !== $domain || bccomp($old->fee,$fee,18)!==0) throw new \LogicException('Conflicting referral event replay');
            return $old->reward_total;
        }
        $user = User::find($userId);
        if (!$user) throw new \LogicException('Referral source user missing');
        $parent = $user->referral_id; $seen = [$userId=>true]; $ancestry = []; $rows = []; $total = '0';
        foreach (self::RATES as $level => $rate) {
            if (!$parent) break;
            if (isset($seen[$parent])) throw new \LogicException('Exchange referral ancestry cycle');
            $seen[$parent] = true; $recipient = User::find($parent);
            if (!$recipient) throw new \LogicException('Exchange referral ancestor missing');
            $depth = min(8, max(1, (int)$recipient->vip));
            $eligible = $level <= $depth && !$recipient->deleted && !$recipient->deactivated;
            $amount = $eligible ? bcdiv(bcmul($fee, (string)$rate, 26), '100', 8) : '0';
            // A virtual trade never pays real funds, even if the recipient's virtual wallet is empty.
            $rewardDomain = $domain === 'unknown' ? 'unknown' : ($domain === 'virtual' || ($recipient->is_xn || $recipient->is_xm) ? 'virtual' : 'real');
            $ancestry[] = ['user_id'=>$recipient->id, 'level'=>$level, 'vip'=>(int)$recipient->vip, 'rate'=>$rate, 'eligible'=>$eligible, 'amount'=>$amount, 'balance_domain'=>$rewardDomain];
            if (bccomp($amount,'0',18)>0) {
                $rows[] = ['event_key'=>$key,'reward_level'=>$level,'user_id'=>$recipient->id,'transaction_id'=>$transactionId,'currency_id'=>$currencyId,'amount'=>$amount,'balance_domain'=>$rewardDomain,'credit_status'=>$domain === 'unknown' ? 'review' : 'pending','last_error'=>$domain === 'unknown' ? 'source_domain_unknown' : null,'is_credited'=>false,'created_at'=>now(),'updated_at'=>now()];
                $total = bcadd($total,$amount,18);
            }
            $parent = $recipient->referral_id;
        }
        DB::table('exchange_referral_events')->insert(['id'=>$key,'business'=>$business,'source_id'=>$sourceId,'source_user_id'=>$userId,'currency_id'=>$currencyId,'fee'=>$fee,'reward_total'=>$total,'balance_domain'=>$domain,'rule_version'=>self::VERSION,'rules'=>json_encode(self::RATES,JSON_THROW_ON_ERROR),'ancestry'=>json_encode($ancestry,JSON_THROW_ON_ERROR),'created_at'=>now()]);
        if ($rows) DB::table('referral_transactions')->insert($rows);
        return $total;
    }

    public function credit(int $id): string
    {
        return DB::transaction(function () use ($id) {
            $row = DB::table('referral_transactions')->where('id',$id)->lockForUpdate()->first();
            if (!$row || $row->is_credited) return 'skipped';
            if (!$row->event_key || !in_array($row->credit_status,['pending','failed'],true)) return 'review';
            $event = DB::table('exchange_referral_events')->find($row->event_key);
            $recipient = User::find($row->user_id);
            $snapshot = collect(json_decode($event?->ancestry ?? '[]',true))->first(fn($a)=>(int)$a['user_id']===(int)$row->user_id && (int)$a['level']===(int)$row->reward_level);
            $reason = !$event || !$snapshot || !$snapshot['eligible'] || bccomp($snapshot['amount'],$row->amount,18)!==0 || $snapshot['balance_domain']!==$row->balance_domain || (int)$event->currency_id!==(int)$row->currency_id ? 'source_mismatch' : null;
            if (!$recipient || $recipient->deleted || $recipient->deactivated) $reason = 'recipient_unavailable';
            if (($recipient?->is_xn || $recipient?->is_xm) && $row->balance_domain==='real') $reason = 'recipient_domain_changed';
            if (!in_array($row->balance_domain,['real','virtual'],true) || ($event?->balance_domain==='virtual' && $row->balance_domain!=='virtual')) $reason = 'domain_mismatch';
            if ($reason) {
                DB::table('referral_transactions')->where('id',$id)->update(['credit_status'=>'review','last_error'=>$reason,'updated_at'=>now()]);
                return 'review';
            }
            $wallet = DB::table('wallets')->where('user_id',$row->user_id)->where('currency_id',$row->currency_id)->lockForUpdate()->first();
            if (!$wallet) {
                DB::table('referral_transactions')->where('id',$id)->update(['credit_status'=>'failed','last_error'=>'wallet_missing','updated_at'=>now()]);
                return 'failed';
            }
            $field = $row->balance_domain==='virtual' ? 'balance_in_virtual_trade' : 'balance_in_wallet';
            if (!property_exists($wallet,$field)) throw new \LogicException('Referral target wallet field missing');
            if (DB::table('exchange_referral_receipts')->where('referral_id',$id)->exists()) throw new \LogicException('Receipt exists for an uncredited referral');
            $before = (string)($wallet->$field ?? '0'); $after = bcadd($before,$row->amount,18);
            DB::table('wallets')->where('id',$wallet->id)->update([$field=>$after,'updated_at'=>now()]);
            DB::table('exchange_referral_receipts')->insert(['referral_id'=>$id,'wallet_id'=>$wallet->id,'balance_field'=>$field,'amount'=>$row->amount,'balance_before'=>$before,'balance_after'=>$after,'created_at'=>now()]);
            DB::table('referral_transactions')->where('id',$id)->update(['is_credited'=>true,'credit_status'=>'credited','credited_at'=>now(),'last_error'=>null,'updated_at'=>now()]);
            return 'credited';
        },3);
    }
}
