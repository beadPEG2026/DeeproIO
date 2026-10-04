<?php

namespace App\Models\Support;

use App\Models\FileUpload\FileUpload;
use App\Models\User\User;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class SupportMessage extends Model
{
    use HasFactory;

    protected $fillable = [
        'user_id',
        'title',
        'body',
        'file_id',
        'status',
        'reply',
        'replied_at',
        'ticket_id',
        'request_key',
    ];

    protected $casts = [
        'revision' => 'integer',
        'assigned_to' => 'integer',
        'replied_at' => 'datetime:Y-m-d H:i:s',
        'created_at' => 'datetime:Y-m-d H:i:s',
    ];

    protected static function booted()
    {
        static::created(function (SupportMessage $message) {
            if (empty($message->ticket_id)) {
                $message->ticket_id = generate_string();
                // Use quiet saving to avoid events loop
                $message->saveQuietly();
            }
        });
    }

    public function entries() { return $this->hasMany(SupportTicketEntry::class)->orderBy('id'); }

    public function user()
    {
        return $this->belongsTo(User::class, 'user_id');
    }

    public function file()
    {
        return $this->belongsTo(FileUpload::class, 'file_id');
    }
}
