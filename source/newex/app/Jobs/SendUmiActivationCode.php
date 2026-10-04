<?php
namespace App\Jobs;

use App\Models\Umi\LegacyAccount;
use App\Mail\UmiActivationCode;
use App\Services\Umi\LegacyActivation;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\{ShouldQueue,ShouldBeEncrypted};
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\{InteractsWithQueue,SerializesModels};
use Illuminate\Support\Facades\{DB,Mail};

final class SendUmiActivationCode implements ShouldQueue,ShouldBeEncrypted
{
    use Dispatchable,InteractsWithQueue,Queueable,SerializesModels;
    public int $tries=3;
    public int $timeout=30;
    public function __construct(public string $challengeId, private string $code, private string $language = 'zh-cn') {}
    public function backoff():array { return [5,15,30]; }
    public function handle():void {
        DB::transaction(function(){
            $hint=DB::table('umi_activation_challenges')->find($this->challengeId);
            if(!$hint)return;
            $account=LegacyAccount::whereKey($hint->legacy_id)->lockForUpdate()->first();
            $c=DB::table('umi_activation_challenges')->where('id',$this->challengeId)->lockForUpdate()->first();
            if(!$c||$c->used_at||now()->gte($c->expires_at)||$c->delivery_state==='sent'||!$account||!$account->approved_email||$account->user_id||$account->legacy_status===0)return;
            if(!hash_equals($c->email_lookup,LegacyActivation::lookup($account->approved_email)))return;
            $email=$account->approved_email;
            $mailer=config('mail.default');
            $mail=(new UmiActivationCode($this->code, 'activation'))->locale($this->language);
            Mail::mailer($mailer)->to($email)->send($mail);
            DB::table('umi_activation_challenges')->where('id',$c->id)->update(['delivery_state'=>'sent','sent_at'=>now()->toIso8601String()]);
            \Illuminate\Support\Facades\Log::info('UMI verification submitted to mail transport', ['purpose'=>'activation','challenge_id'=>$c->id,'mailer'=>$mailer]);
        });
    }
    public function failed(?\Throwable $exception):void {
        DB::table('umi_activation_challenges')->where('id',$this->challengeId)->where('delivery_state','!=','sent')->update(['delivery_state'=>'failed']);
    }
}
