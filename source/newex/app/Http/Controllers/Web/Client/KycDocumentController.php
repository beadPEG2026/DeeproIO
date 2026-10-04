<?php

namespace App\Http\Controllers\Web\Client;

use App\Http\Controllers\Controller;
use App\Http\Controllers\Api\v1\EmailVerificationCodeController;
use App\Http\Requests\Web\User\KycFormRequest;
use App\Http\Resources\Country\CountryCollection;
use App\Http\Resources\KycDocument\KycDocument;
use App\Mail\KycDocuments\AdminKycReceived;
use App\Repositories\Country\CountryRepository;
use App\Repositories\KycDocument\KycDocumentRepository;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Redirect;
use Illuminate\Support\Facades\Schema;
use Inertia\Inertia;
use Setting;

class KycDocumentController extends Controller
{
    public function index()
    {
        $user = auth()->user();

        $kycStatus = Setting::get('general.kyc_status');

        if (!$kycStatus) {
            return Inertia::render('Kyc/Index', [
                'sumsub' => false,
                'sumsub_pending' => false,
                'sumsub_rejected' => false,
                'isVerified' => $user && $user->kyc_verified_at ? true : false,
                'countries' => [
                    'data' => [],
                ],
                'pendingDocument' => null,
                'rejectedDocument' => null,
                'disabled' => true,
                'filters' => [
                    'search' => null,
                    'period' => [],
                    'team_user_id' => null,
                    'role' => null,
                    'status' => null,
                    'kyc_status' => null,
                    'email_status' => null,
                    'login_ip' => null,
                    'ip_location' => null,
                    'duplicate_ip' => null,
                    'duplicate_account' => null,
                    'referrer' => null,
                ],
            ]);
        }

        $countries = new CountryCollection((new CountryRepository())->get());

        $kycDocumentRepository = new KycDocumentRepository();

        $userId = $user->getAuthIdentifier();

        $kycPendingDocument = $kycDocumentRepository->getByStatus(
            KYC_DOCUMENT_STATUS_PENDING,
            $userId
        );

        $kycRejectedDocument = $kycDocumentRepository->getByStatus(
            KYC_DOCUMENT_STATUS_REJECTED,
            $userId
        );

        return Inertia::render('Kyc/Index', [
            'sumsub' => false,
            'sumsub_pending' => $user->sumsub_review_status == "pending",
            'sumsub_rejected' => $user->sumsub_applicant_status == "rejected",
            'isVerified' => $user->kyc_verified_at ? true : false,
            'countries' => $countries,
            'pendingDocument' => $kycPendingDocument ? new KycDocument($kycPendingDocument) : null,
            'rejectedDocument' => $kycRejectedDocument ? new KycDocument($kycRejectedDocument) : null,
            'disabled' => false,
            'filters' => [
                'search' => null,
                'period' => [],
                'team_user_id' => null,
                'role' => null,
                'status' => null,
                'kyc_status' => null,
                'email_status' => null,
                'login_ip' => null,
                'ip_location' => null,
                'duplicate_ip' => null,
                'duplicate_account' => null,
                'referrer' => null,
            ],
        ]);
    }

    /**
     * Store the resource.
     *
     * @return \Illuminate\Http\Response
     */
    public function store(KycFormRequest $request)
    {
        $data = $request->only([
            'first_name',
            'last_name',
            'middle_name',
            'country_id',
            'document_type',
            'document_number',
            'selfie_id',
            'back_id',
            'front_id',
        ]);

        $user = auth()->user();
        $userId = $user->getAuthIdentifier();

        /*
         * KYC 提交前验证手机验证码。
         *
         * 规则：
         * 1. 前端提交 phone 和 phone_code。
         * 2. 手机验证码必须验证通过后才能提交 KYC。
         * 3. 如果 users.phone 为空，验证通过后把本次验证的手机号写入 users.phone。
         * 4. 如果 users.phone 已有值，则不覆盖，并要求本次验证手机号与已绑定手机号一致。
         */
        $phone = trim((string) $request->input('phone', ''));
        $phoneCode = trim((string) $request->input('phone_code', ''));

        if ($phone === '') {
            return Redirect::back()
                ->withErrors([
                    'phone' => __('Please enter a valid mobile phone number.'),
                ])
                ->withInput();
        }

        if (!preg_match('/^\+[1-9]\d{6,29}$/', $phone)) {
            return Redirect::back()
                ->withErrors([
                    'phone' => __('Please enter a valid mobile phone number with country code.'),
                ])
                ->withInput();
        }

        if (!EmailVerificationCodeController::hasAllowedPhoneCountryCode($phone)) {
            return Redirect::back()
                ->withErrors([
                    'phone' => __('Please select an allowed country code.'),
                ])
                ->withInput();
        }

        if ($phoneCode === '' || !preg_match('/^\d{6}$/', $phoneCode)) {
            return Redirect::back()
                ->withErrors([
                    'phone_code' => __('Phone verification code must be 6 digits.'),
                ])
                ->withInput();
        }

        $currentUserPhone = trim((string) ($user->phone ?? ''));

        if ($currentUserPhone !== '' && $currentUserPhone !== $phone) {
            return Redirect::back()
                ->withErrors([
                    'phone' => __('The phone number must match your account phone number.'),
                ])
                ->withInput();
        }

        /*
         * 防止不同账号绑定相同手机号。
         */
        $phoneUsedByOtherUser = \App\Models\User\User::query()
            ->where('id', '!=', $userId)
            ->where('phone', $phone)
            ->exists();

        if ($phoneUsedByOtherUser) {
            return Redirect::back()
                ->withErrors([
                    'phone' => __('This phone number is already registered.'),
                ])
                ->withInput();
        }

        if (!EmailVerificationCodeController::verifyPhoneCode($phone, $phoneCode)) {
            return Redirect::back()->withErrors([
                'phone_code' => __('Invalid or expired phone verification code.'),
            ])->withInput();
        }

        /*
         * 如果 users.phone 为空，验证码通过后写入手机号。
         */
        if ($currentUserPhone === '') {
            $user->phone = $phone;
            $user->save();
        }

        /**
         * 身份證號字段。
         *
         * 如果你的数据库字段不是 document_number，
         * 例如叫 id_number / identity_number，
         * 这里和前端、KycFormRequest、数据库字段都要统一。
         */
        $identityColumn = 'document_number';

        if (!Schema::hasColumn('kyc_documents', $identityColumn)) {
            return Redirect::back()
                ->withErrors([
                    $identityColumn => __('KYC document number field does not exist.'),
                ])
                ->withInput();
        }

        $normalizeName = function ($value) {
            $value = trim((string) $value);
            $value = preg_replace('/\s+/u', ' ', $value);

            return mb_strtolower($value);
        };

        $normalizeDocumentNumber = function ($value) {
            $value = trim((string) $value);
            $value = preg_replace('/[\s\-]+/u', '', $value);

            return mb_strtoupper($value);
        };

        $firstName = $normalizeName($data['first_name'] ?? '');
        $lastName = $normalizeName($data['last_name'] ?? '');
        $middleName = $normalizeName($data['middle_name'] ?? '');
        $documentNumber = $normalizeDocumentNumber($data[$identityColumn] ?? '');

        if ($documentNumber === '') {
            return Redirect::back()
                ->withErrors([
                    $identityColumn => __('Please enter your ID number.'),
                ])
                ->withInput();
        }

        /**
         * 保存前统一格式，避免：
         * 123456
         * 123 456
         * 123-456
         * 被当成不同身份证号。
         */
        $data[$identityColumn] = $documentNumber;

        /**
         * 相同国家 + 相同姓名 + 相同身份证号，只允许一个账号使用。
         *
         * 说明：
         * 1. 不同 user_id：直接禁止。
         * 2. 当前 user_id 自己如果已有 pending / approved，也禁止重复提交。
         * 3. 如果当前用户之前被拒绝，可以允许重新提交。
         */
        $duplicateQuery = DB::table('kyc_documents')
            ->select(['id', 'user_id', 'status'])
            ->where('country_id', $data['country_id'])
            ->whereRaw('LOWER(TRIM(COALESCE(first_name, \'\'))) = ?', [$firstName])
            ->whereRaw('LOWER(TRIM(COALESCE(last_name, \'\'))) = ?', [$lastName])
            ->whereRaw('LOWER(TRIM(COALESCE(middle_name, \'\'))) = ?', [$middleName])
            ->whereRaw(
                'UPPER(REPLACE(REPLACE(TRIM(COALESCE(' . $identityColumn . ', \'\')), \' \', \'\'), \'-\', \'\')) = ?',
                [$documentNumber]
            );

        $duplicateQuery->where(function ($query) use ($userId) {
            /**
             * 别人的相同身份信息，永远禁止。
             */
            $query->where('user_id', '!=', $userId);

            /**
             * 自己如果已经有非拒绝记录，也禁止重复提交。
             */
            if (defined('KYC_DOCUMENT_STATUS_REJECTED')) {
                $query->orWhere(function ($subQuery) use ($userId) {
                    $subQuery->where('user_id', $userId)
                        ->where('status', '!=', KYC_DOCUMENT_STATUS_REJECTED);
                });
            } else {
                $query->orWhere('user_id', $userId);
            }
        });

        $duplicate = $duplicateQuery->first();

        if ($duplicate) {
            return Redirect::back()
                ->withErrors([
                    $identityColumn => __('This ID number has already been used for identity verification.'),
                ])
                ->withInput();
        }

        $data['user_id'] = $userId;
        $data['status'] = KYC_DOCUMENT_STATUS_PENDING;

        (new KycDocumentRepository())->store($data);

        /*
         * KYC 提交成功后清理本次手机验证码。
         */
        EmailVerificationCodeController::forgetPhoneCode($phone);

        /**
         * Admin Email Notification
         */
        $adminEmail = Setting::get('notification.admin_email', false);
        $notificationAllowed = Setting::get('notification.kyc_received', false);

        if ($adminEmail && $notificationAllowed) {
            $route = route('admin.kyc.documents') . "?search=" . $user->email;

            Mail::to($adminEmail)->queue(
                new AdminKycReceived($user->email, $route)
            );
        }

        return Redirect::route('user.kyc');
    }
}