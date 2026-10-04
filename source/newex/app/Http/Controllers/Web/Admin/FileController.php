<?php

namespace App\Http\Controllers\Web\Admin;

use App\Http\Controllers\Controller;
use App\Models\FileUpload\FileUpload;
use Illuminate\Http\Request;

class FileController extends Controller
{
    public function upload(Request $request){

        $request->validate([
            'file' => 'required|image|mimes:jpg,jpeg,png|max:5120'
        ]);

        $fileUpload = new FileUpload();

        if($request->file()) {

            $file_name = md5($request->file->getClientOriginalName() . time() . uniqid()) . '.' . $request->file->extension();
            $file_path = $request->file('file')->storeAs('uploads', $file_name, 'public');
            $full_path = '/storage/' . $file_path;

            $fileUpload->name = $file_name;
            $fileUpload->path = $full_path;
            $fileUpload->save();

            return response()->json([
                'uuid'=> $fileUpload->id,
                'path' => url($full_path)
            ]);
        }

        abort(404);
    }

    public function delete(Request $request){

        $request->validate([
            'uuid' => 'required|exists:file_uploads,id'
        ]);

        $fileUpload = FileUpload::findOrFail($request->get('uuid'));

        abort_if($fileUpload->is_private || $fileUpload->bound_at, 403);
        if (is_file(public_path($fileUpload->path))) unlink(public_path($fileUpload->path));

        $fileUpload->delete();
    }
}
