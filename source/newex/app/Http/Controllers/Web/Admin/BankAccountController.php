<?php

namespace App\Http\Controllers\Web\Admin;

use App\Http\Controllers\Controller;
use App\Http\Requests\Web\BankAccount\BankAccountFormRequest;
use App\Models\BankAccount\BankAccount;
use App\Models\Currency\Currency;
use App\Repositories\BankAccount\BankAccountRepository;
use App\Repositories\Country\CountryRepository;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Redirect;
use Inertia\Inertia;
use Setting;

class BankAccountController extends Controller
{
    /**
     * @var BankAccountRepository
     */
    protected $bankAccountRepository;

    /**
     * BankAccountController Constructor
     *
     * @param BankAccountRepository $bankAccountRepository
     */
    public function __construct(BankAccountRepository $bankAccountRepository)
    {
        $this->bankAccountRepository = $bankAccountRepository;
    }

    public function index(Request $request)
    {
        $filters = $request->all([
            'search',
            'status_type',
            'user_id',
            'per_page',
        ]);

        $bankAccounts = $this->bankAccountRepository->get($filters);

        return Inertia::render('Admin/BankAccounts/Index', [
            'filters' => $filters,
            'bankAccounts' => $bankAccounts,
        ]);
    }

    /**
     * Create new resource.
     *
     * @return \Illuminate\Http\Response
     */
    public function create()
    {
        $countries = (new CountryRepository())->get();

        return Inertia::render('Admin/BankAccounts/Form', [
            'countries' => $countries,
        ]);
    }

    /**
     * Store new resource.
     *
     * @return \Illuminate\Http\Response
     */
    public function store(BankAccountFormRequest $request)
    {
        $this->bankAccountRepository->store($request->only([
            'reference_number',
            'name',
            'iban',
            'swift',
            'ifsc',
            'address',
            'account_holder_name',
            'account_holder_address',
            'note',
            'status',
            'status_type',
            'country_id',
            'user_id',
        ]));

        return Redirect::route('admin.bank_accounts');
    }

    /**
     * Edit resource.
     *
     * @param BankAccount $bankAccount
     * @return \Inertia\Response
     */
    public function edit(BankAccount $bankAccount)
    {
        $countries = (new CountryRepository())->get();
        $account = $this->bankAccountRepository->getBankAccountById($bankAccount->id);

        return Inertia::render('Admin/BankAccounts/Form', [
            'isEdit' => true,
            'bankAccount' => $account,
            'countries' => $countries,
        ]);
    }

    /**
     * Update resource.
     *
     * @param BankAccount $bankAccount
     * @return \Illuminate\Http\RedirectResponse
     */
    public function update(BankAccountFormRequest $request, BankAccount $bankAccount)
    {
        $this->bankAccountRepository->update($bankAccount->id, $request->only([
            'reference_number',
            'name',
            'iban',
            'swift',
            'ifsc',
            'address',
            'account_holder_name',
            'account_holder_address',
            'note',
            'status',
            'status_type',
            'country_id',
            'user_id',
        ]));

        return Redirect::route('admin.bank_accounts');
    }

    public function moderate(Request $request, BankAccount $bankAccount)
    {
        $data = $request->validate([
            'action' => 'required|in:approve,reject',
        ]);

        $statusType = $data['action'] === 'approve' ? 1 : 2;

        $this->bankAccountRepository->updateStatusType($bankAccount->id, $statusType);

        return Redirect::route('admin.bank_accounts');
    }

    /**
     * Destroy resource.
     *
     * @param BankAccount $bankAccount
     * @return \Illuminate\Http\RedirectResponse
     */
    public function destroy(BankAccount $bankAccount)
    {
        $currency = Currency::where('bank_account', $bankAccount->id)->first();

        if ($currency) {
            return Redirect::back()->withErrors([
                'name' => 'This Bank Account was used by this currency: ' . $currency->symbol . '. Detach it before deleting.'
            ]);
        }

        $this->bankAccountRepository->delete($bankAccount->id);

        return Redirect::route('admin.bank_accounts');
    }
}