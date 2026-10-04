<?php
namespace App\Models\Support;
use Illuminate\Database\Eloquent\Model;
class SupportTicketEntry extends Model {
    public function file() { return $this->belongsTo(\App\Models\FileUpload\FileUpload::class); }
    protected $guarded = ['id'];
    protected $casts = ['changes'=>'array'];
}
