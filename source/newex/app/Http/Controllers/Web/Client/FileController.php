<?php

namespace App\Http\Controllers\Web\Client;

use App\Http\Controllers\Controller;
use App\Models\FileUpload\FileUpload;
use App\Modules\P2P\Models\PeerTrade\PeerOrder;
use App\Modules\P2P\Repositories\PeerTrade\PeerOrderRepository;
use Illuminate\Http\Request;
use Intervention\Image\File;
use Intervention\Image\Image;
use Intervention\Image\ImageManager;

class FileController extends Controller
{
    public function upload(Request $request){

        $request->validate([
            'file' => 'required|image|mimes:jpg,jpeg,png|max:5120'
        ]);

        abort_unless($request->user(), 401);
        $fileUpload = new FileUpload();
        $fileUpload->owner_id = $request->user()->id;

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

    public function uploadMerchant(Request $request){

        $request->validate([
            'file' => 'required|mimes:jpg,jpeg,png,pdf|max:5120'
        ]);

        abort_unless($request->user(), 401);
        $fileUpload = new FileUpload();
        $fileUpload->owner_id = $request->user()->id;

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

    public function uploadDocument(Request $request){

        $imageExtensions = ['jpg','jpeg','png', 'gif'];

        $request->validate([
            'file' => 'required|mimes:jpg,jpeg,png,gif,mp4,mov,ogg,qt,pdf,video/mp4,video/quicktime,application/x-mpegURL,video/x-flv,video/MP2T,video/x-ms-wmv|max:51120'
        ]);

        abort_unless($request->user(), 401);
        $fileUpload = new FileUpload();
        $fileUpload->owner_id = $request->user()->id;

        if($request->file()) {

            $absName = md5($request->file->getClientOriginalName() . time() . uniqid());
            $file_name =  $absName. '.' . $request->file->extension();
            $file_path = $request->file('file')->storeAs('uploads', $file_name, 'public');
            $type = 'media';

            if(in_array($request->file->extension(), $imageExtensions)) {

                $dirPrefix = 'storage/uploads/';
                $thumb_name = $dirPrefix . $absName . '_cropped.' . $request->file->extension();

                $image = ImageManager::gd()->read(public_path($dirPrefix . $file_name));
                $image->scale(width: 90)->save(public_path($thumb_name));
                $type = 'image';
            }

            $full_path = '/storage/' . $file_path;
            $fileUpload->name = $file_name;
            $fileUpload->type = $type;
            $fileUpload->path = $full_path;
            $fileUpload->save();

            $orderId = $request->header('ORDER-ID');

            if($orderId) {

                $user = $request->user();

                $order = PeerOrder::where('id', $orderId)->first();

                // Security: Verify user is a participant in this order
                if (!$order || ($order->user_id !== $user->id && $order->ad_user_id !== $user->id)) {
                    return response()->json(['error' => 'Unauthorized access to order'], 403);
                }

                $post['message'] = $full_path;
                $post['type'] = $type;
                $post['order_id'] = $orderId;
                $post['is_author'] = $order->user_id == $user->id;
                $post['user_id'] = $user->id;
                $post['is_visible_owner'] = true;
                $post['is_visible_counterparty'] = true;
                $post['order_owner_id'] = $order->user_id;
                $post['order_counterparty_id'] = $order->ad_user_id;
                $post['id'] = generate_uuid();

                $peerOrderRepository = new PeerOrderRepository();
                $peerOrderRepository->storeMessage($post);
            }

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

        abort_unless($request->user() && (int)$fileUpload->owner_id === (int)$request->user()->id, 403);
        // Used uploads must remain available as evidence, including legacy KYC and support records.
        $used = $fileUpload->bound_at || \App\Models\Support\SupportMessage::where('file_id', $fileUpload->id)->exists()
            || \App\Models\Support\SupportTicketEntry::where('file_id', $fileUpload->id)->exists()
            || \App\Models\KycDocument\KycDocument::where('selfie_id', $fileUpload->id)
                ->orWhere('front_id', $fileUpload->id)->orWhere('back_id', $fileUpload->id)->exists();
        foreach (['kyc_documents_file_uploads','peer_merchant_documents_file_uploads','peer_order_appeals_file_uploads'] as $table) {
            if (\Illuminate\Support\Facades\Schema::hasTable($table) && \Illuminate\Support\Facades\DB::table($table)->where('file_id', $fileUpload->id)->exists()) $used = true;
        }
        abort_if($used, 409, 'An attached file cannot be deleted.');
        // Serialize against ticket attachment claims. Retain public legacy blobs for existing consumers.
        \Illuminate\Support\Facades\DB::transaction(function () use ($fileUpload) {
            $locked = FileUpload::whereKey($fileUpload->id)->lockForUpdate()->firstOrFail();
            abort_if($locked->bound_at, 409);
            if ($locked->is_private) \Illuminate\Support\Facades\Storage::disk('local')->delete($locked->path);
            $locked->delete();
        });

        return response()->json([]);
    }
}
