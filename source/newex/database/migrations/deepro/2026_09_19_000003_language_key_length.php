<?php
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
return new class extends Migration {
    public function up():void {
        \Illuminate\Support\Facades\Schema::table('language_translations',fn(Blueprint $t)=>$t->string('key',512)->change());
    }
    public function down():void {
        // Keep the wider column on rollback: shrinking would truncate translations.
    }
};
