<?php

namespace App\Models\Article\Traits\Relations;

use App\Models\FileUpload\FileUpload;

trait ArticleRelation
{
    public function file()
    {
        return $this->belongsTo(FileUpload::class, 'file_id');
    }
}


