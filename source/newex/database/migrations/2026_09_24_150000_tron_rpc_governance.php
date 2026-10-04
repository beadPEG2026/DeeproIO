<?php
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\{DB, Schema};

return new class extends Migration {
    public function up(): void
    {
        Schema::create('rpc_provider_budgets', function (Blueprint $t) {
            $t->string('key_hash', 64)->primary();
            $t->integer('interval_ms')->default(1000);
            $t->timestampTz('next_at')->nullable();
            $t->timestampTz('blocked_until')->nullable();
            $t->bigInteger('php_requests')->default(0);
            $t->bigInteger('node_requests')->default(0);
            $t->bigInteger('rate_limits')->default(0);
            $t->integer('consecutive_limits')->default(0);
            $t->integer('last_status')->nullable();
            $t->timestampTz('updated_at')->nullable();
        });
        // Both runtimes call the same atomic admission function. Database time avoids
        // clock skew. This MUST run on an autocommit connection, outside ledger locks.
        DB::unprepared(<<<'SQL'
CREATE FUNCTION deepro_rpc_acquire(k text, initial_ms integer, caller text)
RETURNS TABLE(admitted boolean, wait_ms integer) LANGUAGE plpgsql AS $$
DECLARE b rpc_provider_budgets%ROWTYPE; n timestamptz; due timestamptz;
BEGIN
  INSERT INTO rpc_provider_budgets(key_hash,interval_ms) VALUES(k,GREATEST(100,initial_ms)) ON CONFLICT DO NOTHING;
  SELECT * INTO b FROM rpc_provider_budgets WHERE key_hash=k FOR UPDATE;
  n := clock_timestamp();
  due := GREATEST(COALESCE(b.next_at,n),COALESCE(b.blocked_until,n));
  IF due > n THEN RETURN QUERY SELECT false, LEAST(2147483647,CEIL(EXTRACT(EPOCH FROM (due-n))*1000))::integer; RETURN; END IF;
  UPDATE rpc_provider_budgets SET next_at=n+interval_ms*interval '1 millisecond',
    php_requests=php_requests+CASE WHEN caller='php' THEN 1 ELSE 0 END,
    node_requests=node_requests+CASE WHEN caller='node' THEN 1 ELSE 0 END,updated_at=n WHERE key_hash=k;
  RETURN QUERY SELECT true,0;
END $$;
CREATE FUNCTION deepro_rpc_outcome(k text, status integer, retry_seconds integer)
RETURNS void LANGUAGE plpgsql AS $$
BEGIN
  UPDATE rpc_provider_budgets SET last_status=status, updated_at=clock_timestamp(),
    blocked_until=CASE WHEN status IN (429,403) THEN GREATEST(blocked_until,
      clock_timestamp()+GREATEST(retry_seconds,CASE WHEN status=403 THEN 60 ELSE LEAST(300,20*POWER(2,LEAST(consecutive_limits,4)))::integer END)*interval '1 second') ELSE blocked_until END,
    rate_limits=rate_limits+CASE WHEN status IN (429,403) THEN 1 ELSE 0 END,
    consecutive_limits=CASE WHEN status IN (429,403) THEN LEAST(consecutive_limits+1,10) WHEN status BETWEEN 200 AND 299 THEN 0 ELSE consecutive_limits END
  WHERE key_hash=k;
END $$;
SQL);
    }
    public function down(): void
    {
        DB::unprepared('DROP FUNCTION IF EXISTS deepro_rpc_acquire(text,integer,text); DROP FUNCTION IF EXISTS deepro_rpc_outcome(text,integer,integer);');
        Schema::dropIfExists('rpc_provider_budgets');
    }
};
