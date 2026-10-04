<?php
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\{DB,Schema};
return new class extends Migration {
    public function up(): void {
        $fields=['balance_in_wallet','balance_in_trade','balance_in_order','balance_in_withdraw','balance_in_lc'];
        if(DB::table('wallets')->select('user_id','currency_id')->groupBy('user_id','currency_id')->havingRaw('COUNT(*)>1')->exists()) throw new RuntimeException('Wallet duplicates require reconciliation; no records were removed.');
        $fields=array_values(array_filter($fields,fn($f)=>Schema::hasColumn('wallets',$f)));
        foreach($fields as $field) if(DB::table('wallets')->where($field,'<',0)->exists()) throw new RuntimeException('Negative wallet balance requires reconciliation; no balances were changed.');
        Schema::table('wallets',fn(Blueprint $t)=>$t->unique(['user_id','currency_id'],'wallet_user_currency_unique'));
        if(DB::getDriverName()==='pgsql') DB::statement('ALTER TABLE wallets ADD CONSTRAINT wallet_real_balances_nonnegative CHECK ('.implode(' AND ',array_map(fn($f)=>$f.' >= 0',$fields)).')');
    }
    public function down(): void {
        if(DB::getDriverName()==='pgsql')DB::statement('ALTER TABLE wallets DROP CONSTRAINT IF EXISTS wallet_real_balances_nonnegative');
        Schema::table('wallets',fn(Blueprint $t)=>$t->dropUnique('wallet_user_currency_unique'));
    }
};
