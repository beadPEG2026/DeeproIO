<?php

namespace App\Http\Controllers\Web\Admin;

use App\Http\Controllers\Controller;
use App\Http\Requests\Web\Network\NetworkFormRequest;
use App\Models\Network\Network;
use App\Repositories\Network\NetworkRepository;
use Illuminate\Support\Facades\Redirect;
use Inertia\Inertia;

class NetworkController extends Controller
{
    /**
     * @var NetworkRepository
     */
    protected $networkRepository;

    /**
     * NetworkController Constructor
     *
     * @param NetworkRepository $networkRepository
     *
     */
    public function __construct(NetworkRepository $networkRepository)
    {
        $this->networkRepository = $networkRepository;
    }


    public function index()
    {
        $networks = $this->networkRepository->get();

        return Inertia::render('Admin/Networks/Index', [
            'networks' => $networks,
        ]);
    }

    /**
     * Edit resource.
     *
     * @param Network $network
     * @return \Inertia\Response
     */
    public function edit(Network $network)
    {
        $network = $this->networkRepository->getById($network->id, false);

        return Inertia::render('Admin/Networks/Form', [
            'isEdit' => true,
            'network' => $network,
        ]);
    }

    /**
     * Update resource.
     *
     * @param Network $network
     * @return \Illuminate\Http\RedirectResponse
     */
    public function update(NetworkFormRequest $request, Network $network)
    {
        $this->networkRepository->update($network->id, request()->only('slug', 'name', 'deposit_status','withdraw_status'));

        return Redirect::route('admin.networks');
    }
}
