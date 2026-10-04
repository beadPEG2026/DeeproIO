<?php
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration {
    public function up(): void
    {
        // Keep the old function for compatible rollback/old workers. Reservations
        // eliminate repeated races between a scanner and consecutive custody calls.
        DB::unprepared(<<<'SQL'
CREATE FUNCTION deepro_rpc_reserve(k text, initial_ms integer, caller text)
RETURNS TABLE(admitted boolean, wait_ms integer) LANGUAGE plpgsql AS $$
DECLARE b rpc_provider_budgets%ROWTYPE; n timestamptz; due timestamptz;
BEGIN
  INSERT INTO rpc_provider_budgets(key_hash,interval_ms) VALUES(k,GREATEST(100,initial_ms)) ON CONFLICT DO NOTHING;
  SELECT * INTO b FROM rpc_provider_budgets WHERE key_hash=k FOR UPDATE;
  n := clock_timestamp();
  IF b.blocked_until > n THEN
    RETURN QUERY SELECT false,LEAST(2147483647,CEIL(EXTRACT(EPOCH FROM (b.blocked_until-n))*1000))::integer; RETURN;
  END IF;
  due := GREATEST(COALESCE(b.next_at,n),n);
  IF due > n+interval '5 seconds' THEN
    RETURN QUERY SELECT false,5001; RETURN;
  END IF;
  UPDATE rpc_provider_budgets SET next_at=due+interval_ms*interval '1 millisecond',
    php_requests=php_requests+CASE WHEN caller='php' THEN 1 ELSE 0 END,
    node_requests=node_requests+CASE WHEN caller='node' THEN 1 ELSE 0 END,updated_at=n WHERE key_hash=k;
  RETURN QUERY SELECT true,GREATEST(0,CEIL(EXTRACT(EPOCH FROM (due-n))*1000))::integer;
END $$;
SQL);
    }
    public function down(): void {DB::unprepared('DROP FUNCTION IF EXISTS deepro_rpc_reserve(text,integer,text);');}
};
