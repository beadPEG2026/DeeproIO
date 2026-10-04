<?php

namespace App\Repositories\KycDocument;

use App\Mail\KycDocuments\KycApproved;
use App\Mail\KycDocuments\KycRejected;
use App\Models\KycDocument\KycDocument;
use App\Models\User\User;
use Carbon\Carbon;
use Illuminate\Support\Facades\Mail;

class KycDocumentRepository
{
    /**
     * @return KycDocument Collection
     */
    public function get()
    {
        $kycDocument = KycDocument::query();

        $kycDocument->with(['country', 'selfie', 'back', 'front', 'user.roles']);

        $kycDocument->filter(request()->only(['search']));

        $this->applyFilters($kycDocument, request()->all([
            'period',
            'role',
            'status',
            'kyc_status',
            'email_status',
            'login_ip',
            'ip_location',
            'duplicate_ip',
            'duplicate_account',
        ]));

        $this->applyAdminTeamScope($kycDocument, 'kyc_documents.user_id');

        return $kycDocument->orderBy('id', 'desc')
            ->paginate(50)
            ->withQueryString();
    }

    protected function applyFilters($kycDocument, array $filters): void
    {
        if (!empty($filters['role'])) {
            $role = $filters['role'];

            $kycDocument->whereHas('user.roles', function ($query) use ($role) {
                $query->where('name', $role);
            });
        }

        if (!empty($filters['status'])) {
            $status = $filters['status'];

            $kycDocument->whereHas('user', function ($query) use ($status) {
                if ($status === 'normal') {
                    $query->where('deactivated', 0);
                }

                if ($status === 'deactivated') {
                    $query->where('deactivated', 1);
                }
            });
        }

        if (!empty($filters['kyc_status'])) {
            $kycDocument->where('status', $filters['kyc_status']);
        }

        if (!empty($filters['email_status'])) {
            $emailStatus = $filters['email_status'];

            $kycDocument->whereHas('user', function ($query) use ($emailStatus) {
                if ($emailStatus === 'verified') {
                    $query->whereNotNull('email_verified_at');
                }

                if ($emailStatus === 'unverified') {
                    $query->whereNull('email_verified_at');
                }
            });
        }

        if (!empty($filters['login_ip'])) {
            $loginIp = trim((string) $filters['login_ip']);

            $kycDocument->whereHas('user', function ($query) use ($loginIp) {
                $query->where('login_ip', 'like', '%' . $loginIp . '%');
            });
        }

        if (!empty($filters['ip_location'])) {
            $ipLocation = trim((string) $filters['ip_location']);

            $kycDocument->whereHas('user', function ($query) use ($ipLocation) {
                $query->where('ip_location', 'like', '%' . $ipLocation . '%');
            });
        }

        $period = $filters['period'] ?? [];

        if (is_array($period) && !empty($period[0]) && !empty($period[1])) {
            $start = strlen($period[0]) <= 10 ? $period[0] . ' 00:00:00' : $period[0];
            $end = strlen($period[1]) <= 10 ? $period[1] . ' 23:59:59' : $period[1];

            $kycDocument->whereBetween('created_at', [$start, $end]);
        }

        if (!empty($filters['duplicate_ip'])) {
            $kycDocument->whereHas('user', function ($query) {
                $query->whereIn('login_ip', function ($sub) {
                    $sub->select('login_ip')
                        ->from('users')
                        ->whereNotNull('login_ip')
                        ->where('login_ip', '!=', '')
                        ->groupBy('login_ip')
                        ->havingRaw('COUNT(*) > 1');
                });
            });
        }

        if (!empty($filters['duplicate_account'])) {
            $kycDocument->whereHas('user', function ($query) {
                $query->whereIn('email', function ($sub) {
                    $sub->select('email')
                        ->from('users')
                        ->whereNotNull('email')
                        ->where('email', '!=', '')
                        ->groupBy('email')
                        ->havingRaw('COUNT(*) > 1');
                });
            });
        }
    }

    /**
     * @return KycDocument Collection
     */
    public function getByStatus($status, $user_id)
    {
        $kycDocument = KycDocument::query();

        $kycDocument->whereStatus($status);

        $kycDocument->where('user_id', $user_id);

        $kycDocument->orderBy('id', 'desc');

        return $kycDocument->first();
    }

    /**
     * @param $data
     * @return KycDocument
     */
    public function store($data)
    {
        $kycDocument = (new KycDocument())->create($data);

        return $kycDocument->fresh();
    }

    /**
     * Moderate Kyc Document
     */
    public function moderate($document, $action)
    {
        if ($action == "approve") {
            $document->status = KYC_DOCUMENT_STATUS_APPROVED;
            $document->user->kyc_verified_at = Carbon::now();
            $document->user->name = $document->first_name . " " . $document->last_name;
            $document->user->update();

            $document->update();

            Mail::to($document->user)->queue(new KycApproved($document->user));
        } else {
            $document->status = KYC_DOCUMENT_STATUS_REJECTED;
            $document->rejected_reason = nl2br(request()->get('reason'));
            $document->save();

            Mail::to($document->user)->queue(new KycRejected($document->user, $document->rejected_reason));
        }
    }

    public function count()
    {
        $document = KycDocument::query();

        return $document->count();
    }

    protected function getCurrentRoleIds(): array
    {
        $currentUser = auth()->user();

        if (!$currentUser) {
            return [];
        }

        $currentUser->loadMissing('roles');

        return $currentUser->roles
            ->pluck('id')
            ->map(function ($id) {
                return (int) $id;
            })
            ->toArray();
    }

    protected function getCurrentRoleNames(): array
    {
        $currentUser = auth()->user();

        if (!$currentUser) {
            return [];
        }

        $currentUser->loadMissing('roles');

        return $currentUser->roles
            ->pluck('name')
            ->filter()
            ->values()
            ->toArray();
    }

    protected function isSuperAdmin(): bool
    {
        $roleNames = $this->getCurrentRoleNames();

        if (in_array('superadmin', $roleNames, true)) {
            return true;
        }

        return (auth()->user()?->hasRole('superadmin') ?? false);
    }

    protected function hasTeamDataScope(): bool
    {
        $roleNames = $this->getCurrentRoleNames();

        $teamScopeRoles = [
            'admin',
            'user_leader',
            'salesman',
            'user_editor',
            'perm_users',
        ];

        if (count(array_intersect($roleNames, $teamScopeRoles)) > 0) {
            return true;
        }

        return (auth()->user()?->hasRole('admin') ?? false);
    }

    protected function applyAdminTeamScope($query, string $userColumn)
    {
        $currentUser = auth()->user();

        if (!$currentUser) {
            return $query->whereRaw('1 = 0');
        }

        if ($this->isSuperAdmin()) {
            return $query;
        }

        if ($this->hasTeamDataScope()) {
            $teamUserIds = $this->getAllTeamUserIds((int) $currentUser->id);

            if (empty($teamUserIds)) {
                return $query->whereRaw('1 = 0');
            }

            return $query->whereIn($userColumn, $teamUserIds);
        }

        return $query->whereRaw('1 = 0');
    }

    protected function getAllTeamUserIds(int $userId): array
    {
        $allIds = [$userId];
        $pendingIds = [$userId];

        while (!empty($pendingIds)) {
            $children = User::query()
                ->whereIn('referral_id', $pendingIds)
                ->pluck('id')
                ->map(function ($id) {
                    return (int) $id;
                })
                ->toArray();

            $children = array_values(array_diff($children, $allIds));

            if (empty($children)) {
                break;
            }

            $allIds = array_merge($allIds, $children);
            $pendingIds = $children;
        }

        return array_values(array_unique(array_map('intval', $allIds)));
    }
}
