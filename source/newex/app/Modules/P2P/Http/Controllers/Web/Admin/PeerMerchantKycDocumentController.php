<?php

namespace App\Modules\P2P\Http\Controllers\Web\Admin;

use App\Http\Controllers\Controller;
use App\Models\KycDocument\KycDocument;
use App\Modules\P2P\Models\PeerTrade\PeerMerchantKycDocument;
use App\Modules\P2P\Repositories\PeerTrade\PeerTradeMerchantKycRepository;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Redirect;
use Inertia\Inertia;

class PeerMerchantKycDocumentController extends Controller
{
    /**
     * @var PeerTradeMerchantKycRepository
     */
    protected $kycDocumentRepository;

    /**
     * KycDocumentController Constructor
     *
     * @param PeerTradeMerchantKycRepository $kycDocumentRepository
     *
     */
    public function __construct(PeerTradeMerchantKycRepository $kycDocumentRepository)
    {
        $this->kycDocumentRepository = $kycDocumentRepository;
    }


    public function index()
    {
        $kycDocuments = $this->kycDocumentRepository->get();

        return Inertia::render('Admin/PeerTrades/MerchantApplications', [
            'kycDocuments' => $kycDocuments,
            'filters' => request()->all(['search', 'referrer']),
        ]);
    }


    public function moderate(Request $request, PeerMerchantKycDocument $document)
    {
        $this->kycDocumentRepository->moderate($document, $request->get('action'));

        return Redirect::route('admin.peerMerchantApplications');
    }

}
