<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('support_messages', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('user_id');
            $table->string('title');
            $table->text('body');
            $table->unsignedBigInteger('file_id')->nullable();
            $table->string('status')->default('new');
            $table->timestamps();

            $table->index('user_id');
            $table->index('file_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('support_messages');
    }
};
