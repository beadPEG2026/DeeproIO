<?php

namespace App\Modules\P2P\Repositories\PeerTrade;

use App\Modules\P2P\Mail\MerchantApplications\MerchantKycApproved;
use App\Modules\P2P\Mail\MerchantApplications\MerchantKycRejected;
use App\Modules\P2P\Models\PeerTrade\PeerMerchantKycDocument;
use Carbon\Carbon;
use Illuminate\Support\Facades\Mail;

class PeerTradeMerchantKycRepository
{
    /**
     * @return PeerMerchantKycDocument Collection
     */
    public function get() {

        $kycDocument = PeerMerchantKycDocument::query();

        $kycDocument->with(['country', 'file', 'user']);

        $kycDocument->filter(request()->only(['search']));

        return $kycDocument->orderBy('id', 'desc')
            ->paginate(50)
            ->withQueryString();
    }

    /**
     * @return PeerMerchantKycDocument Collection
     */
    public function getByStatus($status, $user_id) {

        $kycDocument = PeerMerchantKycDocument::query();

        $kycDocument->whereStatus($status);

        $kycDocument->where('user_id', $user_id);

        $kycDocument->orderBy('id', 'desc');

        return $kycDocument->first();
    }

    /**
     * @param $data
     * @return PeerMerchantKycDocument
     */
    public function store($data) {

        $kycDocument = (new PeerMerchantKycDocument())->create($data);

        return $kycDocument->fresh();
    }

    /**
     * Moderate Kyc Document
     */
    public function moderate($document, $action) {

        if($action == "approve") {
            $document->status = KYC_DOCUMENT_STATUS_APPROVED;
            $document->user->merchant_verified_at = Carbon::now();
            $document->user->update();
            $document->update();

            // Notify user
            Mail::to($document->user->email)->queue(new MerchantKycApproved($document->user));

        } else {
            $document->status = KYC_DOCUMENT_STATUS_REJECTED;
            $document->rejected_reason = nl2br(request()->get('reason'));
            $document->save();

            // Notify user
            Mail::to($document->user->email)->queue(new MerchantKycRejected($document->user, $document->rejected_reason));
        }


    }

    public function count() {
        $document = PeerMerchantKycDocument::query();
        return $document->count();
    }

}
