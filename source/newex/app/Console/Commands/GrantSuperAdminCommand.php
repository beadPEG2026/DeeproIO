<?php

namespace App\Console\Commands;

use App\Models\User\User;
use App\Services\Operations\History;
use App\Services\User\FreshUserIdentity;
use Illuminate\Console\Command;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\{DB, Hash};
use Illuminate\Support\Str;
use RuntimeException;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;

/** Console-only, additive account provisioning; never resets another operator. */
class GrantSuperAdminCommand extends Command
{
    protected $signature = 'deepro:grant-superadmin
        {email : Exact email of the account to grant}
        {--actor= : Existing active superadmin ID authorizing the operation}
        {--reason= : Audit reason}
        {--request-key= : Unique UUID for an idempotent application}
        {--expect-new : Require an absent account, as observed in the preview}
        {--expect-user-id= : Require this existing user ID, as observed in the preview}
        {--apply : Persist the reviewed change; omitted means read-only preview}';

    protected $description = 'Preview or add a superadmin without replacing credentials or other accounts';

    public function handle(): int
    {
        $email = Str::lower(trim((string) $this->argument('email')));
        if (!filter_var($email, FILTER_VALIDATE_EMAIL) || strlen($email) > 254) {
            $this->error('Provide a valid email address.');
            return self::FAILURE;
        }

        try {
            $role = Role::where('name', 'superadmin')->where('guard_name', 'web')->first();
            if (!$role) throw new RuntimeException('The existing web superadmin role is missing.');
            $target = $this->target($email);
            if (!$this->option('apply')) {
                $this->line(json_encode($this->result($email, $target, false, 'preview'), JSON_THROW_ON_ERROR));
                return self::SUCCESS;
            }

            $actorId = (string) $this->option('actor');
            $reason = trim((string) $this->option('reason'));
            $key = (string) $this->option('request-key');
            $expectedId = (string) $this->option('expect-user-id');
            if (!ctype_digit($actorId) || (int) $actorId < 1 || strlen($reason) < 10 || strlen($reason) > 1000 || !Str::isUuid($key)) {
                throw new RuntimeException('Apply requires an actor ID, a 10–1000 character audit reason and a UUID request key.');
            }
            if ((bool) $this->option('expect-new') === ($expectedId !== '')) {
                throw new RuntimeException('Apply requires exactly one of --expect-new or --expect-user-id.');
            }
            if ($expectedId !== '' && (!ctype_digit($expectedId) || (int) $expectedId < 1)) {
                throw new RuntimeException('Expected user ID must be a positive integer.');
            }
            if (config('app.readonly')) throw new RuntimeException('Account provisioning is disabled in read-only mode.');
            if (DB::getDriverName() !== 'pgsql') throw new RuntimeException('Account provisioning requires PostgreSQL.');

            $result = DB::transaction(function () use ($email, $role, $actorId, $reason, $key, $expectedId) {
                // Same lock order as FreshUserIdentity; also serializes case-insensitive email creation.
                DB::statement("SET LOCAL lock_timeout = '10s'");
                DB::statement('LOCK TABLE users IN SHARE ROW EXCLUSIVE MODE');
                $actor = User::find((int) $actorId);
                if (!$actor || $actor->deleted || $actor->deactivated || !$actor->hasRole('superadmin', 'web')) {
                    throw new RuntimeException('The audit actor must be an existing active superadmin.');
                }
                $target = $this->target($email);
                $prior = DB::table('operations_events')->where('request_key', $key)->first();
                if ($prior) {
                    $change = json_decode($prior->changes, true, 512, JSON_THROW_ON_ERROR);
                    if ($prior->object_type !== 'admin_account' || $prior->action !== 'superadmin.granted'
                        || (int) $prior->actor_id !== $actor->id || !$target || (int) $prior->object_id !== $target->id
                        || ($change['email_sha256'] ?? '') !== hash('sha256', $email)
                        || !$target->hasRole('superadmin', 'web')) {
                        throw new RuntimeException('Request key conflicts with another operation or the account has changed.');
                    }
                    $result = $this->result($email, $target, false, 'already_applied');
                    $result['credential_action'] = $change['credential_action'];
                    return $result;
                }

                if ($this->option('expect-new') && $target) throw new RuntimeException('Account now exists. Preview and use its exact user ID.');
                if ($expectedId !== '' && (!$target || $target->id !== (int) $expectedId)) {
                    throw new RuntimeException('The account no longer matches the previewed user ID.');
                }
                $created = $target === null;
                $before = $target ? $target->getRoleNames()->sort()->values()->all() : [];
                if (!$target) {
                    // No known password, reset token or implicit email verification is issued.
                    // The owner must use the existing email-based forgot-password flow.
                    // Skip registration observers: this admin task must not create wallets or referrals.
                    $id = app(FreshUserIdentity::class)->allocate();
                    $target = User::withoutEvents(fn () => User::forceCreate([
                        'id' => $id, 'name' => 'Deepro Administrator', 'email' => $email,
                        'password' => Hash::make(bin2hex(random_bytes(48))),
                        'active' => 1, 'deleted' => false, 'deactivated' => false,
                        'email_verified_at' => null,
                        'language_id' => DB::table('languages')->where('is_default', true)->value('id'),
                    ]));
                }
                $changed = !$target->hasRole('superadmin', 'web');
                if ($changed) $target->assignRole($role);
                $target->unsetRelation('roles');
                History::append('admin_account', $target->id, 'superadmin.granted', [
                    'email_sha256' => hash('sha256', $email),
                    'created_account' => $created, 'role_added' => $changed,
                    'before_roles' => $before, 'after_roles' => $target->getRoleNames()->sort()->values()->all(),
                    'credential_action' => $created ? 'owner_password_reset_required' : 'existing_credentials_preserved',
                    'email_sent' => false,
                ], $actor->id, $reason, $key);
                return $this->result($email, $target, $changed, 'applied', $created);
            });
            app(PermissionRegistrar::class)->forgetCachedPermissions();
            $this->line(json_encode($result, JSON_THROW_ON_ERROR));
            return self::SUCCESS;
        } catch (QueryException $e) {
            // Query exceptions can contain bound password hashes. Never echo them.
            $this->error('Database operation failed; no account change was committed. SQLSTATE '.(string) $e->getCode());
            return self::FAILURE;
        } catch (RuntimeException $e) {
            $this->error($e->getMessage());
            return self::FAILURE;
        }
    }

    private function target(string $email): ?User
    {
        $matches = User::whereRaw('lower(email) = ?', [$email])->get();
        if ($matches->count() > 1) throw new RuntimeException('Multiple accounts match this email. Review before granting access.');
        $target = $matches->first();
        if ($target && ($target->deleted || $target->deactivated)) {
            throw new RuntimeException('The account is disabled. This command will not reactivate it.');
        }
        if ($target && $target->getRawOriginal('email') !== $email) {
            throw new RuntimeException('Stored email capitalization differs from login normalization. Review the identity first.');
        }
        return $target;
    }

    private function result(string $email, ?User $target, bool $changed, string $status, bool $created = false): array
    {
        return [
            'status' => $status, 'email' => $email, 'user_id' => $target?->id,
            'account_exists' => $target !== null, 'account_created' => $created, 'role_changed' => $changed,
            'roles' => $target ? $target->getRoleNames()->sort()->values()->all() : [],
            'email_verified' => $target?->hasVerifiedEmail() ?? false,
            'two_factor_enabled' => $target ? !empty($target->two_factor_secret) && $target->two_factor_confirmed_at !== null : false,
            'credential_action' => !$target || $created ? 'owner_password_reset_required' : 'existing_credentials_preserved',
            'email_sent' => false,
        ];
    }
}
