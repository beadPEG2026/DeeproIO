<?php

namespace App\Http\Controllers\Web\Admin;

use App\Http\Controllers\Controller;
use App\Http\Requests\Web\ColdStorage\ColdStorageFormRequest;
use App\Models\ColdStorage\ColdStorage;
use App\Repositories\ColdStorage\ColdStorageRepository;
use App\Repositories\Currency\CurrencyRepository;
use App\Repositories\Network\NetworkRepository;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Redirect;
use Illuminate\Support\Facades\Schema;
use Inertia\Inertia;
use PragmaRX\Google2FA\Google2FA;
use Setting;

class ColdStorageController extends Controller
{
    /**
     * @var ColdStorageRepository
     */
    protected $coldStorageRepository;

    /**
     * ColdStorageController Constructor
     *
     * @param ColdStorageRepository $coldStorageRepository
     */
    public function __construct(ColdStorageRepository $coldStorageRepository)
    {
        $this->coldStorageRepository = $coldStorageRepository;

        $this->middleware(function ($request,$next) {
            abort_unless(\App\Services\Custody\CustodyAccess::allowed($request->user()),403);
            return $next($request);
        });
    }
    public function verifyGoogleCode(Request $request) {
        \App\Services\Custody\CustodyAccess::verify($request);
        return response()->json(['success'=>true,'expires_in'=>300]);
    }
    protected function ensureGoogleVerified(Request $request) {
        \App\Services\Custody\CustodyAccess::requireFresh($request);
        return null;
    }

    public function index()
    {
        return Redirect::route('admin.custody');
    }

    /**
     * Create new resource.
     *
     * @return \Illuminate\Http\Response
     */
    public function create()
    {
        $networks = (new NetworkRepository())->get(true, true);
        $currencies = $this->assetOptions($networks);

        return Inertia::render('Admin/ColdStorage/Form', [
            'currencies' => $currencies,
            'networks' => $networks,
            'needGoogleVerify' => !\App\Services\Custody\CustodyAccess::fresh(request()),
            'twoFactorConfigured' => !empty(request()->user()->two_factor_secret),
        ]);
    }

    /**
     * Store new resource.
     *
     * @return \Illuminate\Http\Response
     */
    public function store(ColdStorageFormRequest $request)
    {
        if ($response = $this->ensureGoogleVerified($request)) {
            return $response;
        }

        app(\App\Services\Custody\ColdRuleService::class)->save($request->validated(), $request->user()->id);

        return Redirect::route('admin.cold_storage');
    }

    /**
     * Edit resource.
     *
     * @param ColdStorage $coldStorage
     * @return \Inertia\Response
     */
    public function edit(ColdStorage $coldStorage)
    {
        $networks = (new NetworkRepository())->get(true, true);
        $currencies = $this->assetOptions($networks);

        $coldStorage = $this->coldStorageRepository->getColdStorageById($coldStorage->id);

        return Inertia::render('Admin/ColdStorage/Form', [
            'isEdit' => true,
            'coldStorage' => $coldStorage,
            'currencies' => $currencies,
            'networks' => $networks,
            'needGoogleVerify' => !\App\Services\Custody\CustodyAccess::fresh(request()),
            'twoFactorConfigured' => !empty(request()->user()->two_factor_secret),
        ]);
    }

    /**
     * Update resource.
     *
     * @param ColdStorage $coldStorage
     * @return \Illuminate\Http\RedirectResponse
     */
    public function update(ColdStorageFormRequest $request, ColdStorage $coldStorage)
    {
        if ($response = $this->ensureGoogleVerified($request)) {
            return $response;
        }

        app(\App\Services\Custody\ColdRuleService::class)->save($request->validated(), $request->user()->id, $coldStorage->id);

        return Redirect::route('admin.cold_storage');
    }

    /**
     * 修改入金银行卡。
     *
     * 入金银行卡已经迁移到独立的配置表。
     * 此操作不会修改链上冷钱包规则。
     */
/**
 * 修改入金银行卡。
 *
 * 兼容旧入口，读写独立的 fiat_deposit_instructions 记录。
 */
public function updateDepositBankCard(Request $request, $coldStorage)
{
    \App\Services\Custody\CustodyAccess::requireFresh($request);
    abort_unless((int)$coldStorage===1,403);
    $data=$request->validate(['address'=>'nullable|string|max:2000','status'=>'required|boolean']);
    if($data['status']&&!trim((string)($data['address']??'')))return response()->json(['errors'=>['address'=>[__('Required')]]],422);
    DB::transaction(function()use($data){DB::table('fiat_deposit_instructions')->where('id',1)->update(['address'=>trim((string)($data['address']??'')),'status'=>$data['status'],'updated_by'=>auth()->id(),'updated_at'=>now()]);app(\App\Services\Custody\CustodyService::class)->audit('fiat_instructions.updated',['enabled'=>$data['status']]);});
    return response()->json(['success'=>true]);
}

    /**
     * Destroy resource.
     *
     * @param ColdStorage $coldStorage
     * @return \Illuminate\Http\RedirectResponse
     */
    public function destroy(ColdStorage $coldStorage)
    {
        if ($response = $this->ensureGoogleVerified(request())) {
            return $response;
        }

        app(\App\Services\Custody\ColdRuleService::class)->remove($coldStorage->id,auth()->id());

        return Redirect::route('admin.cold_storage');
    }

    private function assetOptions($networks)
    {
        $allowedIds = $networks->pluck('id')->map(fn($id) => (int) $id)->all();
        return (new CurrencyRepository())->all(false, false, ['networks'], 'coin')->map(fn($currency) => [
            'id' => $currency->id, 'name' => $currency->name, 'symbol' => $currency->symbol,
            'network_options' => \App\Services\Wallet\AssetNetworkOptions::forCurrency($currency, $allowedIds),
        ]);
    }
}
