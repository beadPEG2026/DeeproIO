<?php

namespace App\Models\KycDocument;

use App\Models\KycDocument\Traits\Scopes\KycDocumentScope;
use App\Models\KycDocument\Traits\Relations\KycDocumentRelation;
use Illuminate\Database\Eloquent\Model;

class KycDocument extends Model
{
    use KycDocumentRelation, KycDocumentScope;

    public $fillable = [
        'user_id',
        'first_name',
        'last_name',
        'middle_name',
        'country_id',
        'document_type',
        'document_number',
        'date_of_birth',
        'phone_number',
        'rejected_reason',
        'back_id',
        'front_id',
        'selfie_id',
        'status',
    ];

    protected $casts = [
        'created_at' => "datetime:Y-m-d H:i:s",
    ];
}