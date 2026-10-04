<?php
/*
 *  Copyright 2021. Crypto Smart Solutions, LLC
 *  Protected with Proprietary License
 */

namespace App\Models\FileUpload;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class FileUpload extends Model
{
    use HasFactory;

    protected $fillable = [
        'name',
        'path'
    ];

    protected $casts = ['is_private' => 'boolean', 'bound_at' => 'datetime'];
    protected $hidden = ['owner_id'];

    public function attributesToArray()
    {
        $data = parent::attributesToArray();
        if ($this->is_private) unset($data['path']);
        return $data;
    }

    protected $appends = ['url', 'type'];

    public function getUrlAttribute()
    {
        return $this->is_private ? route('support.attachments.download', $this->id) : url($this->path);
    }

    public function getTypeAttribute()
    {
        $ext = strtolower(pathinfo((string)$this->path, PATHINFO_EXTENSION));

        $imgMime = ['jpg', 'jpeg', 'png', 'gif'];
        $documentMime = ['pdf'];
        $videoMime = ['mp4', 'avi', 'webm', 'flv', 'wmv', 'mov'];

        if(in_array($ext, $imgMime)) {
            return 'image';
        } elseif(in_array($ext, $documentMime)) {
            return 'document';
        } elseif(in_array($ext, $videoMime)) {
            return 'video';
        } else {
            return 'file';
        }
    }
}
