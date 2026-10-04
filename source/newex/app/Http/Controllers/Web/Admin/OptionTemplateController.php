<?php

namespace App\Http\Controllers\Web\Admin;

use App\Http\Controllers\Controller;
use App\Http\Requests\Web\Option\OptionTemplateFormRequest;
use App\Models\Option\OptionTemplate;
use App\Repositories\Market\MarketRepository;
use App\Repositories\Option\OptionRepository;
use Illuminate\Support\Facades\Redirect;
use Inertia\Inertia;
use Setting;

class OptionTemplateController extends Controller
{
    /**
     * @var OptionRepository
     */
    protected $optionRepository;

    /**
     * VoucherController Constructor
     *
     * @param OptionRepository $optionRepository
     *
     */
    public function __construct(OptionRepository $optionRepository)
    {
        $this->optionRepository = $optionRepository;
    }


    public function index()
    {
        $options = $this->optionRepository->getOptionsTemplates();

        return Inertia::render('Admin/OptionTemplate/Index', [
            'options' => $options,
        ]);
    }

    /**
     * Create new resource.
     *
     * @return \Illuminate\Http\Response
     */
    public function create()
    {
        $markets = (new MarketRepository())->all(false);

        return Inertia::render('Admin/OptionTemplate/Form', [
            'markets' => $markets,
        ]);
    }

    /**
     * Store new resource.
     *
     * @return \Illuminate\Http\Response
     */
    public function store(OptionTemplateFormRequest $request)
    {
        $this->optionRepository->storeTemplate($request->only([
            'market_id',
            'type',
            'period',
            'amount',
            'action',
        ]));

        return Redirect::route('admin.options.templates');
    }

    /**
     * Edit resource.
     *
     * @param OptionTemplate $optionTemplate
     * @return \Inertia\Response
     */
    public function edit(OptionTemplate $optionTemplate)
    {
        $markets = (new MarketRepository())->all(false);
        $optionTemplate = $this->optionRepository->getOptionTemplateId($optionTemplate->id);

        return Inertia::render('Admin/OptionTemplate/Form', [
            'isEdit' => true,
            'option' => $optionTemplate,
            'markets' => $markets,
        ]);
    }

    /**
     * Update resource.
     *
     * @param OptionTemplate $optionTemplate
     * @return \Illuminate\Http\RedirectResponse
     */
    public function update(OptionTemplateFormRequest $request, OptionTemplate $optionTemplate)
    {
        $this->optionRepository->updateTemplate($optionTemplate->id, $request->only([
            'market_id',
            'side',
            'type',
            'period',
            'amount',
            'action',
        ]));

        return Redirect::route('admin.options.templates');
    }

    /**
     * Destroy resource.
     *
     * @param OptionTemplate $optionTemplate
     * @return \Illuminate\Http\RedirectResponse
     */
    public function destroy(OptionTemplate $optionTemplate)
    {
        $this->optionRepository->deleteTemplate($optionTemplate->id);

        return Redirect::route('admin.options.templates');
    }
}
