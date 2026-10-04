<?php
namespace App\Http\Controllers\Web\Client;

use App\Http\Controllers\Controller;
use App\Models\FileUpload\FileUpload;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

class SupportAttachmentController extends Controller
{
    public function store(Request $request)
    {
        $request->validate(['file' => 'required|file|mimes:jpg,jpeg,png,pdf|max:5120']);
        $upload = $request->file('file');
        $path = $upload->storeAs('support', Str::uuid().'.'.$upload->extension(), 'local');
        abort_unless($path, 503);
        try {
            $file = new FileUpload();
            $file->name = mb_substr(preg_replace('/[\x00-\x1F\x7F]/u', '', basename(str_replace('\\', '/', $upload->getClientOriginalName()))), 0, 200) ?: 'attachment';
            $file->path = $path;
            $file->owner_id = $request->user()->id;
            $file->purpose = 'support';
            $file->is_private = true;
            $file->save();
        } catch (\Throwable $e) {
            Storage::disk('local')->delete($path);
            throw $e;
        }
        return response()->json(['uuid' => $file->id, 'name' => $file->name, 'path' => $file->url]);
    }

    public function download(Request $request, FileUpload $file)
    {
        abort_unless($file->is_private && $file->purpose === 'support', 404);
        $owner = (int)$file->owner_id === (int)$request->user()->id;
        $operator = $file->bound_at && $request->user()->hasAnyRole(['superadmin','admin','user_editor','perm_support_tickets']);
        abort_unless($owner || $operator, 404);
        abort_unless(Storage::disk('local')->exists($file->path), 404);
        return Storage::disk('local')->download($file->path, $file->name, [
            'Cache-Control' => 'private, no-store', 'X-Content-Type-Options' => 'nosniff',
        ]);
    }
}
