<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\{DB,Schema};

return new class extends Migration {
    public function up(): void
    {
        Schema::table('umi_v2_live_point_terms', function (Blueprint $t): void {
            $t->dateTimeTz('unlock_at')->nullable()->change();
            $t->dateTimeTz('acquired_at')->nullable();
        });
        foreach (DB::table('umi_v2_live_point_terms as t')->join('umi_v2_stock_point_entries as p','p.id','=','t.point_entry_id')
            ->select('t.*','p.created_at as first_acquired_at')->cursor() as $term) {
            $acquired=\Carbon\CarbonImmutable::parse($term->first_acquired_at)->utc();
            DB::table('umi_v2_live_point_terms')->where('point_entry_id',$term->point_entry_id)->update([
                'acquired_at'=>$acquired->toIso8601String(),'eligible_at'=>$acquired->addDays(60)->toIso8601String(),
                'unlock_at'=>$term->status==='points_only'?null:\Carbon\CarbonImmutable::parse($term->confirmed_at)->utc()->addDays(90)->toIso8601String(),
            ]);
        }
    }
    public function down(): void
    {
        throw new RuntimeException('Keep stock lifecycle timestamps across code rollback.');
    }
};
