<?php

namespace App\Modules\P2P\Http\Controllers\Web\Client;

use App\Http\Controllers\Controller;
use App\Models\KycDocument\KycDocument;
use App\Modules\P2P\Http\Requests\Web\PeerTrade\PeerMerchantKycFormRequest;
use App\Modules\P2P\Http\Resources\PeerMerchantKycDocument;
use App\Modules\P2P\Mail\MerchantApplications\AdminMerchantKycReceived;
use App\Modules\P2P\Repositories\PeerTrade\PeerTradeMerchantKycRepository;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Redirect;
use Inertia\Inertia;
use Setting;

class PeerTradeMerchantKycController extends Controller
{

    public function index()
    {
        $user_id = auth()->user()->getAuthIdentifier();

        $approvedKycDocument = KycDocument::where('user_id', $user_id)->with('country')->whereStatus(KYC_DOCUMENT_STATUS_APPROVED)->first();

        $country = '';

        if($approvedKycDocument) {
            $country = $approvedKycDocument->country->name;
        }

        $kycDocumentRepository = new PeerTradeMerchantKycRepository();



        $kycPendingDocument = $kycDocumentRepository->getByStatus(KYC_DOCUMENT_STATUS_PENDING, $user_id);

        $kycRejectedDocument = $kycDocumentRepository->getByStatus(KYC_DOCUMENT_STATUS_REJECTED, $user_id);

        return Inertia::render('PeerTrade/PeerMerchantKyc', [
            'isVerified' => auth()->user()->kyc_verified_at ? true : false,
            'country' => $country,
            'pendingDocument' => $kycPendingDocument ? new PeerMerchantKycDocument($kycPendingDocument) : null,
            'rejectedDocument' => $kycRejectedDocument ? new PeerMerchantKycDocument($kycRejectedDocument) : null,
        ]);
    }

    /**
     * Store the resource.
     *
     * @return \Illuminate\Http\Response
     */
    public function store(PeerMerchantKycFormRequest $request)
    {
        $data = $request->only([
            'address',
            'postal_code',
            'city',
            'state',
            'document_type',
            'file_id',
        ]);

        $data['user_id'] = auth()->user()->getAuthIdentifier();
        $data['status'] = KYC_DOCUMENT_STATUS_PENDING;

        $approvedKycDocument = KycDocument::where('user_id', $data['user_id'])->with('country')->whereStatus(KYC_DOCUMENT_STATUS_APPROVED)->first();

        if($approvedKycDocument) {
            $data['country_id'] = $approvedKycDocument->country->id;
        }

        (new PeerTradeMerchantKycRepository())->store($data);

        // Admin Email Notification
        $adminEmail = Setting::get('notification.admin_email', false);
        $notificationAllowed = Setting::get('notification.kyc_received', false);

        if($adminEmail && $notificationAllowed) {
            $route = route('admin.peerMerchantApplications') . "?search=" . auth()->user()->email;
            Mail::to($adminEmail)->queue(new AdminMerchantKycReceived(auth()->user()->email, $route));
        }
        // END Admin Email Notification

        return Redirect::route('p2p.kyc');
    }
}
