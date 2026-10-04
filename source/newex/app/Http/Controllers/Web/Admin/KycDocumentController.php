<?php

namespace App\Http\Controllers\Web\Admin;

use App\Http\Controllers\Controller;
use App\Models\KycDocument\KycDocument;
use App\Models\User\User;
use App\Repositories\KycDocument\KycDocumentRepository;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Redirect;
use Inertia\Inertia;

class KycDocumentController extends Controller
{
    /**
     * @var KycDocumentRepository
     */
    protected $kycDocumentRepository;

    /**
     * KycDocumentController Constructor
     *
     * @param KycDocumentRepository $kycDocumentRepository
     */
    public function __construct(KycDocumentRepository $kycDocumentRepository)
    {
        $this->kycDocumentRepository = $kycDocumentRepository;
    }
public function pendingCount()
{
    $pendingStatus = defined('KYC_DOCUMENT_STATUS_PENDING')
        ? KYC_DOCUMENT_STATUS_PENDING
        : 'pending';

    $count = \App\Models\KycDocument\KycDocument::query()
        ->where('status', $pendingStatus)
        ->count();

    return response()->json([
        'count' => $count,
    ]);
}
    public function index()
    {
        $kycDocuments = $this->kycDocumentRepository->get();
        $leaderCache = [];
        $userCache = [];
        $referrerChainUserCache = [];

        $kycDocuments->getCollection()->transform(function ($document) use (&$leaderCache, &$userCache, &$referrerChainUserCache) {
            if ($document->user) {
                $document->user->loadMissing('roles');
                $document->user->nickname = trim((string) ($document->user->nickname ?? ''));
                $document->user->leader_display_name = $this->resolveLeaderDisplayName($document->user, $leaderCache, $userCache);
                $document->user->referrer_chain = $this->buildUserReferrerChain($document->user, $referrerChainUserCache);
                $document->user->referrer_display_name = $this->resolveUserReferrerDisplayName($document->user->referrer_chain);
            }

            return $document;
        });

        return Inertia::render('Admin/KycDocuments/Index', [
            'kycDocuments' => $kycDocuments,
            'filters' => request()->all([
                'search',
                'period',
                'team_user_id',
                'role',
                'status',
                'kyc_status',
                'email_status',
                'login_ip',
                'ip_location',
                'duplicate_ip',
                'duplicate_account',
                'referrer',
            ]),
        ]);
    }

    public function moderate(Request $request, KycDocument $document)
    {
        \App\Support\AdminUserAccess::check($document->user);
        $request->validate(['action' => 'required|in:approve,reject']);
        $this->kycDocumentRepository->moderate($document, $request->get('action'));

        return Redirect::back();
    }

    protected function resolveLeaderDisplayName(User $user, array &$leaderCache, array &$userCache): string
    {
        $user->loadMissing('roles');

        if ($user->hasRole('user_leader')) {
            return $this->formatLeaderName($user);
        }

        $leader = $this->findNearestLeader($user, $leaderCache, $userCache);

        return $leader ? $this->formatLeaderName($leader) : '';
    }

    protected function findNearestLeader(User $user, array &$leaderCache, array &$userCache): ?User
    {
        $userId = (int) $user->id;

        if (array_key_exists($userId, $leaderCache)) {
            return $leaderCache[$userId];
        }

        $referralId = $user->referral_id ?? null;
        $visited = [];
        $depth = 0;

        while ($referralId && $depth < 50) {
            $referralId = (int) $referralId;

            if ($referralId <= 0 || in_array($referralId, $visited, true)) {
                break;
            }

            $visited[] = $referralId;

            if (!array_key_exists($referralId, $userCache)) {
                $userCache[$referralId] = User::query()
                    ->with('roles')
                    ->select([
                        'id',
                        'name',
                        'email',
                        'phone',
                        'wallet_id',
                        'referral_id',
                        'referral_code',
                        'nickname',
                        'leader_nickname',
                    ])
                    ->where('id', $referralId)
                    ->first();
            }

            $parent = $userCache[$referralId];

            if (!$parent) {
                break;
            }

            if ($parent->hasRole('user_leader')) {
                $leaderCache[$userId] = $parent;

                return $parent;
            }

            $referralId = $parent->referral_id ?? null;
            $depth++;
        }

        $leaderCache[$userId] = null;

        return null;
    }

    protected function formatLeaderName(User $leader): string
    {
        foreach (['leader_nickname', 'nickname', 'email', 'phone', 'wallet_id', 'name'] as $field) {
            $value = trim((string) ($leader->{$field} ?? ''));

            if ($value !== '') {
                return $value;
            }
        }

        return 'ID: ' . $leader->id;
    }

    protected function buildUserReferrerChain(User $user, array &$userCache): array
    {
        $chain = [];
        $visited = [];
        $referralId = $user->referral_id ?? null;
        $level = 1;

        while ($referralId && $level <= 50) {
            $referralId = (int) $referralId;

            if ($referralId <= 0 || in_array($referralId, $visited, true)) {
                break;
            }

            $visited[] = $referralId;

            if (!array_key_exists($referralId, $userCache)) {
                $userCache[$referralId] = User::query()
                    ->select([
                        'id',
                        'name',
                        'email',
                        'phone',
                        'wallet_id',
                        'referral_id',
                        'referral_code',
                        'nickname',
                        'leader_nickname',
                    ])
                    ->where('id', $referralId)
                    ->first();
            }

            $parent = $userCache[$referralId];

            if (!$parent) {
                break;
            }

            $chain[] = [
                'level' => $level,
                'id' => (int) $parent->id,
                'account' => $this->formatReferrerAccount($parent),
                'nickname' => $this->formatReferrerNickname($parent),
                'name' => $this->formatReferrerName($parent),
                'referral_code' => $parent->referral_code,
                'leader_nickname' => $parent->leader_nickname,
            ];

            $referralId = $parent->referral_id ?? null;
            $level++;
        }

        return $chain;
    }

    protected function resolveUserReferrerDisplayName(array $chain): string
    {
        if (empty($chain)) {
            return 'N/A';
        }

        $direct = $chain[0];

        foreach (['nickname', 'account', 'name', 'referral_code'] as $field) {
            $value = trim((string) ($direct[$field] ?? ''));

            if ($value !== '' && $value !== '-') {
                return $value;
            }
        }

        return !empty($direct['id']) ? 'ID: ' . $direct['id'] : 'N/A';
    }

    protected function formatReferrerAccount(User $user): string
    {
        foreach (['email', 'phone', 'wallet_id', 'referral_code'] as $field) {
            $value = trim((string) ($user->{$field} ?? ''));

            if ($value !== '') {
                return $value;
            }
        }

        return 'ID: ' . $user->id;
    }

    protected function formatReferrerNickname(User $user): string
    {
        $nickname = trim((string) ($user->nickname ?? ''));

        return $nickname !== '' ? $nickname : '-';
    }

    protected function formatReferrerName(User $user): string
    {
        $name = trim((string) ($user->name ?? ''));

        return $name !== '' ? $name : $this->formatReferrerAccount($user);
    }
}
