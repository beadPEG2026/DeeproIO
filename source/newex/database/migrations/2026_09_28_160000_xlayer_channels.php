<?php
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\{DB, Schema};

return new class extends Migration {
    public function up(): void {
        Schema::table('currencies', function (Blueprint $t) {
            $t->string('xlayer_contract', 50)->nullable();
            foreach (['deposit_fee_xlayer','deposit_fee_xlayer_fixed','withdraw_fee_xlayer','withdraw_fee_xlayer_fixed'] as $field) $t->decimal($field,36,18)->default(0);
        });
        foreach ([24=>'xlayer',25=>'xlayer20'] as $id=>$slug) {
            if (DB::table('networks')->where('id',$id)->orWhere('slug',$slug)->exists()) throw new RuntimeException('X Layer network ID or slug already exists; reconcile before migration.');
            DB::table('networks')->insert(['id'=>$id,'slug'=>$slug,'name'=>$id===24?'X Layer (OKB)':'X Layer (ERC20)','type'=>'coin','status'=>true,'deposit_status'=>false,'withdraw_status'=>false,'created_at'=>now(),'updated_at'=>now()]);
        }
        DB::table('custody_networks')->insert(['chain'=>'xlayer','enabled'=>false,'auto_sweep'=>false,'max_fee'=>'0.01','native_max_fee'=>'0.01','daily_gas_limit'=>0,'confirmations'=>64,'auto_sweep_scope'=>'new_live','created_at'=>now(),'updated_at'=>now()]);
        // No balances, keys, existing channels or UMI records are changed here.
        DB::statement("SELECT setval(pg_get_serial_sequence('networks','id'), GREATEST((SELECT max(id) FROM networks),1),true)");
    }
    public function down(): void {
        throw new RuntimeException('Retain financial schema and records. Disable X Layer and roll back code; do not delete network history.');
    }
};
