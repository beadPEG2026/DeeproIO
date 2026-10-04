<?php
namespace App\Services\Support;

use App\Models\FileUpload\FileUpload;
use Illuminate\Validation\ValidationException;

final class SupportAttachment
{
    /** Caller holds a transaction so one upload cannot be attached twice. */
    public static function claim(?int $id, int $owner): ?int
    {
        if (!$id) return null;
        $file = FileUpload::whereKey($id)->lockForUpdate()->first();
        if (!$file || (int)$file->owner_id !== $owner || !$file->is_private || $file->purpose !== 'support' || $file->bound_at) {
            throw ValidationException::withMessages(['file_id' => __('Upload your own attachment for this request.')]);
        }
        $file->bound_at = now();
        $file->save();
        return $file->id;
    }

    public static function present(?FileUpload $file): ?array
    {
        return $file ? ['id' => $file->id, 'name' => $file->name, 'url' => $file->url] : null;
    }
}
