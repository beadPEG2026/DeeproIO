<?php
namespace App\Console\Commands;

use App\Models\User\User;
use App\Services\Market\BinanceChainListing;
use App\Services\Operations\History;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

final class ListBinanceChainAssets extends Command
{
    protected $signature='deepro:binance-chain-assets {--apply} {--actor=} {--reason=} {--request-key=} {--verified-manifest=} {--manifest-sha256=}';
    protected $description='Dry-run top ten verified Binance assets per enabled chain; --apply lists markets without enabling new transfer routes';

    public function handle(BinanceChainListing $listing):int
    {
        try {
            $manifestPath=(string)$this->option('verified-manifest');$hash=(string)$this->option('manifest-sha256');
            if (($manifestPath === '') !== ($hash === '')) throw new \RuntimeException('BINANCE_MANIFEST_HASH_REQUIRED');
            $manifest=$manifestPath !== '' ? $listing->readVerifiedManifest($manifestPath,$hash) : null;
            $actor=null;$key=(string)$this->option('request-key');$reason=trim((string)$this->option('reason'));
            if($this->option('apply')) {
                $actor=User::find((int)$this->option('actor'));
                if(!$actor||$actor->deleted||$actor->deactivated||!$actor->hasRole('superadmin')||!Str::isUuid($key)||strlen($reason)<8)throw new \RuntimeException('Active superadmin, audit reason and UUID request key required.');
                $prior=DB::table('operations_events')->where('request_key',$key)->first();
                if($prior){if($prior->object_type!=='binance_chain_listing'||(int)$prior->actor_id!==$actor->id||$prior->reason!==$reason||((json_decode($prior->changes,true)['plan']['manifestSha256']??'')!==$hash))throw new \RuntimeException('Request key conflict.');$this->line($prior->changes);return self::SUCCESS;}
            }
            $plan=$listing->fetch($manifest);
            $listing->validateExistingIdentities($plan);
            if(!$this->option('apply')){$this->line(json_encode(['dryRun'=>true]+$plan,JSON_PRETTY_PRINT|JSON_UNESCAPED_UNICODE|JSON_THROW_ON_ERROR));return self::SUCCESS;}
            if(!$plan['uniqueAssets'])throw new \RuntimeException('No verified assets to list.');
            $result=DB::transaction(function()use($listing,$plan,$actor,$key,$reason){
                DB::statement('SELECT pg_advisory_xact_lock(8192035)');
                if(DB::table('operations_events')->where('request_key',$key)->exists())throw new \RuntimeException('Request already completed; retry to read result.');
                $result=['plan'=>$plan,'applied'=>$listing->apply($plan)];
                History::append('binance_chain_listing',$key,'configured',$result,$actor->id,$reason,$key);
                return $result;
            });
            $this->line(json_encode($result,JSON_PRETTY_PRINT|JSON_UNESCAPED_UNICODE|JSON_THROW_ON_ERROR));return self::SUCCESS;
        } catch(\Throwable $e) {
            // Http and DB exceptions may contain signed URLs or account data; never print them.
            $safe=preg_match('/^(?:BINANCE_[A-Z_]+|EXISTING_[A-Z_]+:[A-Z0-9:]+)$/D',$e->getMessage())
                || in_array($e->getMessage(),['Active superadmin, audit reason and UUID request key required.','Request key conflict.','Request already completed; retry to read result.','No verified assets to list.'],true);
            $this->error($safe?$e->getMessage():'Listing source or database unavailable; no changes applied.');return self::FAILURE;
        }
    }
}
