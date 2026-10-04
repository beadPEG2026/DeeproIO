<?php
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Database\Schema\Blueprint;
use App\Models\Deposit\DepositChannel;
use App\Services\Deposit\ConfirmationPolicy;

return new class extends Migration {
    public function up(): void {
        // A migration is a system actor, never an invented operator UID.
        if (DB::getDriverName() === 'pgsql') DB::statement('ALTER TABLE deposit_channel_audits ALTER COLUMN actor_id DROP NOT NULL');
        else Schema::table('deposit_channel_audits', fn(Blueprint $t) => $t->unsignedBigInteger('actor_id')->nullable()->change());
        foreach(DepositChannel::orderBy('id')->lockForUpdate()->get() as $c){
            $minimum=ConfirmationPolicy::minimum($c->chain);
            if($c->confirmations >= $minimum)continue;
            $before=$c->toArray();$valid=$c->config_digest && hash_equals($c->config_digest,$c->digest());
            $c->confirmations=$minimum;$c->config_digest=$valid?$c->digest():null;$c->save();
            DB::table('deposit_channel_audits')->insert(['channel_id'=>$c->id,'actor_id'=>null,'before'=>json_encode($before),'after'=>json_encode($c->toArray()+['operation_source'=>'confirmation-floor-20261002','funded_test_required_for_activation'=>false]),'created_at'=>now()]);
        }
    }
    public function down(): void { /* Never lower confirmations on rollback. */ }
};
