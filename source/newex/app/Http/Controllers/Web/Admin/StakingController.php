<?php

namespace App\Http\Controllers\Web\Admin;

use App\Http\Controllers\Controller;
use App\Http\Requests\Web\Staking\StakingFormRequest;
use App\Models\Staking\Staking;
use App\Repositories\Staking\StakingRepository;
use App\Services\Currency\CurrencyService;
use Illuminate\Support\Facades\Redirect;
use Inertia\Inertia;
use Setting;
use App\Services\Operations\{StakingConfiguration,History};

class StakingController extends Controller
{
    /**
     * @var StakingRepository
     */
    protected $stakingRepository;

    /**
     * StakingController Constructor
     *
     * @param StakingRepository $stakingRepository
     *
     */
    public function __construct(StakingRepository $stakingRepository)
    {
        $this->stakingRepository = $stakingRepository;
    }


    private function productType(?Staking $product = null): int
    {
        $type = $product ? (int) $product->staking_type : (request()->routeIs('admin.stakings.quantify') ? 1 : (int) request()->get('staking_type', 0));
        abort_unless(in_array($type, [0, 1], true), 422);
        abort_unless(auth()->user()?->hasAnyRole(['superadmin', $type === 1 ? 'perm_quantify' : 'perm_stakings']), 403);
        request()->merge(['staking_type' => $type]);
        return $type;
    }

    public function index()
    {
        $type = $this->productType();
        $stakings = $this->stakingRepository->get(true,$type);
        return Inertia::render('Admin/Stakings/Index', [
            'stakings' => $stakings,
            'staking_type' => $type
        ]);
    }

    /**
     * Create new resource.
     *
     * @return \Illuminate\Http\Response
     */
    public function create()
    {
        $type = $this->productType();
        $currencies = (new CurrencyService())->getCurrencies(false, true);
        return Inertia::render('Admin/Stakings/Form', [
            'currencies' => $currencies,
            'staking_type' => $type
        ]);
    }

    /**
     * Store new resource.
     *
     * @return \Illuminate\Http\Response
     */
    public function store(StakingFormRequest $request)
    {
        $type = $this->productType();

        $data=$request->only(StakingConfiguration::FIELDS);
        if($type===0)app(StakingConfiguration::class)->save(null,$data,$request->only(['reason','revision','effective_at','apr_limit','high_apr_ack','reward_user_id','pool_limit']),$request->user()->id);
        else $this->stakingRepository->store($data);

        return Redirect::route('admin.stakings', [
            'staking_type' => $type
        ]);
    }

    /**
     * Edit resource.
     *
     * @param Staking $staking
     * @return \Inertia\Response
     */
    public function edit(Staking $staking)
    {
        $currencies = (new CurrencyService())->getCurrencies(false, true);

        $type = $this->productType($staking);
        $staking = $this->stakingRepository->getStakingById($staking->id);
        return Inertia::render('Admin/Stakings/Form', [
            'isEdit' => true,
            'configuration'=> $type===0 ? ['revision'=>app(StakingConfiguration::class)->revision($staking),'controls'=>app(StakingConfiguration::class)->controls($staking->id),'history'=>History::for('staking_config',$staking->id,20)] : null,
            'staking' => $staking,
            'currencies' => $currencies,
            'staking_type' => $type
        ]);
    }

    /**
     * Update resource.
     *
     * @param Staking $staking
     * @return \Illuminate\Http\RedirectResponse
     */
    public function update(StakingFormRequest $request, Staking $staking)
    {
        $type = $this->productType($staking);
        $data=$request->only(StakingConfiguration::FIELDS);$data['staking_type']=$type;
        if($type===0)app(StakingConfiguration::class)->save($staking,$data,$request->only(['reason','revision','effective_at','apr_limit','high_apr_ack','reward_user_id','pool_limit']),$request->user()->id);
        else $this->stakingRepository->update($staking->id,$data);

        return Redirect::route('admin.stakings', [
    'staking_type' => $type
]);
    }

    /**
     * Destroy resource.
     *
     * @param Staking $staking
     * @return \Illuminate\Http\RedirectResponse
     */
    public function destroy(Staking $staking)
    {
        $this->productType($staking);
        \Illuminate\Support\Facades\DB::transaction(function()use($staking){
            $staking=Staking::whereKey($staking->id)->lockForUpdate()->firstOrFail();
            if (app(\App\Services\Staking\FundedTermProduct::class)->enabled($staking)) {
                throw \Illuminate\Validation\ValidationException::withMessages(['status'=>__('Archive funded products by setting their status to hidden. Their accounting history must be retained.')]);
            }
            History::append('staking_config',$staking->id,'delete',['before'=>$staking->only(StakingConfiguration::FIELDS)],auth()->id(),'Deleted from product management');
            \Illuminate\Support\Facades\DB::table('settings')->where('key','staking.configuration.'.$staking->id)->delete();
            $this->stakingRepository->delete($staking->id);
        });

        return Redirect::route('admin.stakings');
    }
}
