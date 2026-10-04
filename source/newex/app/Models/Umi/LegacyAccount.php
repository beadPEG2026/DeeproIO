<?php
namespace App\Models\Umi;
use Illuminate\Database\Eloquent\Model;
class LegacyAccount extends Model {
    protected $table='umi_legacy_accounts';
    protected $primaryKey='legacy_id';
    public $incrementing=false;
    protected $guarded=[];
    protected $hidden=['identity','profile','approved_email','email_lookup','approved_email_lookup'];
    protected $casts=['identity'=>'encrypted:array','profile'=>'encrypted:array','approved_email'=>'encrypted',
        'activated_at'=>'datetime','legacy_status'=>'integer'];
}
