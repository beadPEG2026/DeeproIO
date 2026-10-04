<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::table('umi_v2_live_wallet_moves', function (Blueprint $t): void {
            // Existing movements used the funding wallet; preserve that historical meaning.
            $t->string('from_bucket', 12)->default('wallet');
            $t->string('to_bucket', 12)->default('wallet');
        });
    }

    public function down(): void
    {
        throw new RuntimeException('Preserve UMI account-bucket evidence; use a forward migration.');
    }
};
