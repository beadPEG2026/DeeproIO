<?php
namespace App\Services\Umi\Business;

use App\Models\User\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/** UMI account codes identify UMI sponsors only. Exchange referral codes are never resolved here. */
final class Invitations
{
    public function resolve(int $user, ?string $code, bool $fixture = false): ?int
    {
        $code = trim((string) $code);
        if ($fixture) {
            abort_unless(app()->environment(['local', 'testing']) && !config('umi-business.funded_live'), 403);
            if ($code === '') return null; // CLI/test fixtures only; not accepted by the HTTP API.
        }
        if ($code === '') $this->fail(__('请填写上级的 UMI 专属邀请码。'));
        if (strlen($code) > 24) $this->fail(__('UMI 邀请码无效。'));
        $parent = DB::table('umi_business_accounts')->where('code', $code)->first();
        if (!$parent || (!$fixture && $parent->fixture)) $this->fail(__('UMI 邀请码无效。'));
        if ((int) $parent->user_id === $user) $this->fail(__('不能使用自己的 UMI 邀请码。'));
        if (!$fixture) {
            $owner = User::find($parent->user_id);
            if (!$owner || $owner->deleted || $owner->deactivated || !$owner->email_verified_at) {
                $this->fail(__('该 UMI 邀请人尚未激活或不可用，请联系上级。'));
            }
        }
        // A new node cannot introduce a cycle, but refuse an already damaged ancestry.
        $seen = []; $cursor = $parent;
        while ($cursor) {
            if (isset($seen[$cursor->id]) || (int) $cursor->user_id === $user) $this->fail(__('UMI 上级关系异常，请联系管理员核对。'));
            $seen[$cursor->id] = true;
            if (!$cursor->parent_id) break;
            $cursor = DB::table('umi_business_accounts')->find($cursor->parent_id);
            if (!$cursor) $this->fail(__('UMI 上级关系异常，请联系管理员核对。'));
        }
        return (int) $parent->id;
    }

    private function fail(string $message): never
    {
        throw ValidationException::withMessages(['parent' => $message]);
    }
}
