<?php
namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

class RepairDatabaseSequences extends Command
{
    protected $signature = 'deepro:database-sequences {--apply : Advance sequences; production requires maintenance mode} {--json}';
    protected $description = 'Inspect and repair PostgreSQL sequence positions without changing business rows';

    public function handle(): int
    {
        if (DB::getDriverName() !== 'pgsql') { $this->error('PostgreSQL is required.'); return 1; }
        if ($this->option('apply') && app()->environment('production') && !app()->isDownForMaintenance()) {
            $this->error('Enter the planned maintenance window before applying sequence repairs.'); return 1;
        }
        $columns = DB::select("SELECT table_name,column_name,column_default FROM information_schema.columns WHERE table_schema='public' AND column_default LIKE 'nextval(%' ORDER BY table_name,column_name");
        $results = [];
        foreach ($columns as $c) {
            if (!preg_match("/nextval\('([a-zA-Z0-9_.]+)'::regclass\)/", $c->column_default, $m)) continue;
            $seq = $m[1];
            $work = function () use ($c, $seq) {
                $grammar = DB::connection()->getQueryGrammar();
                $table = $grammar->wrapTable('public.'.$c->table_name);
                if ($this->option('apply')) DB::statement('LOCK TABLE '.$table.' IN SHARE ROW EXCLUSIVE MODE');
                $state = DB::selectOne('SELECT last_value,is_called FROM '.$grammar->wrapTable($seq));
                $max = (int) DB::table($c->table_name)->max($c->column_name);
                if ($c->table_name === 'users' && $c->column_name === 'id') {
                    $max = max($max, app(\App\Services\User\FreshUserIdentity::class)->highWaterMark());
                }
                $next = (int)$state->last_value + ($state->is_called ? 1 : 0);
                $behind = $next <= $max;
                if ($behind && $this->option('apply')) DB::select('SELECT setval(?::regclass, ?, true)', [$seq, max($max, (int)$state->last_value)]);
                $owned = DB::selectOne('SELECT pg_get_serial_sequence(?, ?) AS seq', ['public.'.$c->table_name, $c->column_name])->seq;
                $ownerFixed = false;
                if (!$owned && $this->option('apply')) {
                    $canOwn = DB::selectOne('SELECT pg_has_role(c.relowner,\'USAGE\') AS allowed FROM pg_class c WHERE c.oid=?::regclass', [$seq])->allowed;
                    $canTable = DB::selectOne('SELECT pg_has_role(c.relowner,\'USAGE\') AS allowed FROM pg_class c WHERE c.oid=?::regclass', ['public.'.$c->table_name])->allowed;
                    if ($canOwn && $canTable) {
                        DB::statement('ALTER SEQUENCE '.$grammar->wrapTable($seq).' OWNED BY '.$table.'.'.$grammar->wrap($c->column_name));
                        $ownerFixed = true;
                    }
                }
                return ['table'=>$c->table_name,'column'=>$c->column_name,'max_id'=>$max,'next_before'=>$next,
                    'behind'=>$behind,'advanced'=>$behind && (bool)$this->option('apply'),
                    'ownership_missing'=>!$owned && !$ownerFixed,'ownership_fixed'=>$ownerFixed];
            };
            $results[] = $this->option('apply') ? DB::transaction($work) : $work();
        }
        $output = ['database'=>DB::connection()->getDatabaseName(),'applied'=>(bool)$this->option('apply'),
            'checked'=>count($results),'behind'=>count(array_filter($results,fn($r)=>$r['behind'])),
            'ownership_missing'=>count(array_filter($results,fn($r)=>$r['ownership_missing'])),'sequences'=>$results];
        if ($this->option('json')) $this->line(json_encode($output, JSON_UNESCAPED_UNICODE|JSON_PRETTY_PRINT));
        else { $this->table(['Table','Max ID','Next before','Behind','Advanced','Ownership missing'],array_map(fn($r)=>[$r['table'],$r['max_id'],$r['next_before'],$r['behind']?'yes':'no',$r['advanced']?'yes':'no',$r['ownership_missing']?'yes':'no'],$results)); }
        return !$this->option('apply') && $output['behind'] > 0 ? 1 : 0;
    }
}
