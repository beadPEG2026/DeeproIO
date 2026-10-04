<?php
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
return new class extends Migration {
 public function up():void {DB::unprepared("CREATE FUNCTION umi_snapshot_append_only() RETURNS trigger LANGUAGE plpgsql AS $$ BEGIN RAISE EXCEPTION 'umi_snapshot_append_only'; END; $$");}
 public function down():void {DB::unprepared('DROP FUNCTION IF EXISTS umi_snapshot_append_only()');}
};
