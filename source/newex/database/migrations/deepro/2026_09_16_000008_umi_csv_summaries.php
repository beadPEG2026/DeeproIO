<?php
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
return new class extends Migration {
    public function up():void {Schema::create('umi_legacy_summaries',function(Blueprint $t){$t->unsignedBigInteger('legacy_id')->primary();$t->text('payload');$t->string('source_hash',64);});}
    public function down():void {Schema::dropIfExists('umi_legacy_summaries');}
};
