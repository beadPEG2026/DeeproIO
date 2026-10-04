<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('umi_v2_invite_aliases', function (Blueprint $t): void {
            $t->string('code', 40)->primary();
            $t->foreignId('member_id')->constrained('umi_v2_members')->restrictOnDelete();
            $t->dateTimeTz('created_at');
        });
        // Previously shared codes remain valid, but no member sees the version prefix.
        foreach (DB::table('umi_v2_members')->where('member_code', 'like', 'V2-%')->orderBy('id')->get() as $member) {
            $new = 'U' . strtoupper(bin2hex(random_bytes(6)));
            while (DB::table('umi_v2_members')->where('member_code', $new)->exists()) {
                $new = 'U' . strtoupper(bin2hex(random_bytes(6)));
            }
            DB::table('umi_v2_invite_aliases')->insert([
                'code' => $member->member_code, 'member_id' => $member->id, 'created_at' => now(),
            ]);
            DB::table('umi_v2_members')->where('id', $member->id)->update(['member_code' => $new]);
        }
        Schema::create('umi_v2_legacy_parent_links', function (Blueprint $t): void {
            $t->foreignId('member_id')->primary()->constrained('umi_v2_members')->restrictOnDelete();
            $t->unsignedBigInteger('parent_legacy_id')->nullable();
            $t->unsignedBigInteger('parent_business_id')->nullable();
            $t->string('status', 24); // no_parent | pending | resolved
            $t->dateTimeTz('created_at');
            $t->dateTimeTz('resolved_at')->nullable();
        });
        if (Schema::hasTable('umi_legacy_accounts')) {
            foreach (DB::table('umi_v2_members')->where('legacy_identity_ref', 'like', 'legacy:%')->get() as $member) {
                $legacyId = (int) substr((string) $member->legacy_identity_ref, 7);
                $legacy = DB::table('umi_legacy_accounts')->where('legacy_id', $legacyId)->first();
                if (!$legacy) { continue; }
                $edge = DB::table('umi_v2_sponsor_edges')
                    ->where('child_member_id', $member->id)->first();
                $parent = $legacy->parent_legacy_id
                    ? DB::table('umi_v2_members')->where('legacy_identity_ref',
                        'legacy:' . $legacy->parent_legacy_id)->first() : null;
                if (!$edge && $parent && $parent->id !== $member->id) {
                    DB::table('umi_v2_sponsor_edges')->insert([
                        'child_member_id' => $member->id, 'parent_member_id' => $parent->id,
                        'source_event_id' => 'historical-backfill:' . $member->id,
                        'assigned_at' => now(), 'reason' => 'historical',
                    ]);
                    $edge = true;
                }
                DB::table('umi_v2_legacy_parent_links')->insert([
                    'member_id' => $member->id,
                    'parent_legacy_id' => $legacy->parent_legacy_id,
                    'parent_business_id' => null,
                    'status' => $edge ? 'resolved' : ($legacy->parent_legacy_id ? 'pending' : 'no_parent'),
                    'created_at' => now(), 'resolved_at' => $edge ? now() : null,
                ]);
            }
        }
        Schema::create('umi_v2_member_activation_audit', function (Blueprint $t): void {
            $t->id();
            $t->string('request_key', 120)->unique();
            $t->foreignId('member_id')->constrained('umi_v2_members')->restrictOnDelete();
            $t->unsignedBigInteger('user_id');
            $t->unsignedBigInteger('actor_id');
            $t->string('basis', 40);
            $t->dateTimeTz('created_at');
        });
        Schema::create('umi_v2_stock_share_accounts', function (Blueprint $t): void {
            $t->foreignId('member_id')->primary()->constrained('umi_v2_members')->restrictOnDelete();
            $t->decimal('locked_shares', 54, 24)->default(0);
            $t->decimal('available_shares', 54, 24)->default(0);
            $t->timestampsTz();
        });
        Schema::create('umi_v2_stock_share_moves', function (Blueprint $t): void {
            $t->id();
            $t->string('request_key', 120)->unique();
            $t->string('kind', 24); // entitlement | unlock | transfer | write_off
            $t->foreignId('from_member_id')->nullable()->constrained('umi_v2_members')->restrictOnDelete();
            $t->foreignId('to_member_id')->nullable()->constrained('umi_v2_members')->restrictOnDelete();
            $t->foreignId('point_entry_id')->nullable()->constrained('umi_v2_stock_point_entries')->restrictOnDelete();
            $t->decimal('shares', 54, 24);
            $t->unsignedBigInteger('actor_id')->nullable();
            $t->string('reason', 240);
            $t->dateTimeTz('created_at');
            $t->index(['from_member_id', 'created_at']);
            $t->index(['to_member_id', 'created_at']);
        });
        Schema::table('umi_v2_live_point_terms', function (Blueprint $t): void {
            $t->unsignedBigInteger('confirmed_by')->nullable();
            $t->dateTimeTz('confirmed_at')->nullable();
            $t->dateTimeTz('tradable_at')->nullable();
        });
    }

    public function down(): void
    {
        foreach (['umi_v2_stock_share_moves', 'umi_v2_stock_share_accounts',
            'umi_v2_member_activation_audit', 'umi_v2_legacy_parent_links', 'umi_v2_invite_aliases'] as $table) {
            if (DB::table($table)->exists()) {
                throw new RuntimeException('UMI membership and stock records must survive rollback');
            }
        }
        Schema::table('umi_v2_live_point_terms', function (Blueprint $t): void {
            $t->dropColumn(['confirmed_by', 'confirmed_at', 'tradable_at']);
        });
        foreach (['umi_v2_stock_share_moves', 'umi_v2_stock_share_accounts',
            'umi_v2_member_activation_audit', 'umi_v2_legacy_parent_links', 'umi_v2_invite_aliases'] as $table) {
            Schema::dropIfExists($table);
        }
    }
};
