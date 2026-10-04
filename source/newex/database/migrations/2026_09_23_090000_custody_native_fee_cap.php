<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void {
        Schema::table('custody_networks', function (Blueprint $table) {
            // Null preserves the previous limit until an operator saves a native cap.
            $table->decimal('native_max_fee', 36, 18)->nullable();
        });
    }

    public function down(): void {
        throw new RuntimeException('Retain custody settings and transaction history; roll back application code only.');
    }
};
