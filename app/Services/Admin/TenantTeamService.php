<?php

namespace App\Services\Admin;

use App\Models\User;
use App\Services\Audit\TenantActivityLogService;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Symfony\Component\HttpKernel\Exception\HttpException;

class TenantTeamService
{
    public function listUsers(int $tenantId): array
    {
        $this->ensureTenant($tenantId);

        $users = DB::table('tenant_user')
            ->join('users', 'users.id', '=', 'tenant_user.user_id')
            ->where('tenant_user.tenant_id', $tenantId)
            ->orderBy('users.id')
            ->get([
                'users.id',
                'users.name',
                'users.email',
                'tenant_user.created_at as member_since',
            ]);

        $rolesByUser = DB::table('tenant_user_role')
            ->join('roles', 'roles.id', '=', 'tenant_user_role.role_id')
            ->where('tenant_user_role.tenant_id', $tenantId)
            ->orderBy('roles.code')
            ->get([
                'tenant_user_role.user_id',
                'roles.code',
                'roles.name',
            ])
            ->groupBy('user_id');

        return $users->map(function (object $user) use ($rolesByUser): array {
            $roles = collect($rolesByUser->get($user->id, []))
                ->map(fn (object $role) => [
                    'code' => (string) $role->code,
                    'name' => (string) $role->name,
                ])
                ->values()
                ->all();

            return [
                'id' => (int) $user->id,
                'name' => (string) $user->name,
                'email' => (string) $user->email,
                'member_since' => (string) $user->member_since,
                'roles' => $roles,
            ];
        })->all();
    }

    public function listRoles(): array
    {
        return DB::table('roles')
            ->orderBy('code')
            ->get(['id', 'code', 'name'])
            ->map(fn (object $role) => [
                'id' => (int) $role->id,
                'code' => (string) $role->code,
                'name' => (string) $role->name,
            ])
            ->all();
    }

    public function createOrAttachUser(int $tenantId, int $actorUserId, array $payload): array
    {
        $this->ensureTenant($tenantId);

        if ($actorUserId <= 0) {
            throw new HttpException(403, 'Authenticated user missing.');
        }

        $email = strtolower(trim((string) ($payload['email'] ?? '')));
        $name = trim((string) ($payload['name'] ?? ''));
        $password = (string) ($payload['password'] ?? '');
        $roleCodes = $this->normalizeRoleCodes($payload['roles'] ?? []);

        if ($email === '' || $name === '') {
            throw new HttpException(422, 'Team user name and email are required.');
        }

        $user = User::query()->whereRaw('lower(email) = ?', [$email])->first();
        $wasCreated = false;

        if ($user === null) {
            if ($password === '') {
                throw new HttpException(422, 'Password is required when creating a new user.');
            }

            $user = User::query()->create([
                'name' => $name,
                'email' => $email,
                'password' => Hash::make($password),
                'email_verified_at' => now(),
            ]);
            $wasCreated = true;
        } else {
            DB::table('users')
                ->where('id', $user->id)
                ->update([
                    'name' => $name,
                    'updated_at' => now(),
                ]);
        }

        $membershipExists = DB::table('tenant_user')
            ->where('tenant_id', $tenantId)
            ->where('user_id', $user->id)
            ->exists();

        if ($membershipExists) {
            DB::table('tenant_user')
                ->where('tenant_id', $tenantId)
                ->where('user_id', $user->id)
                ->update([
                    'updated_at' => now(),
                ]);
        } else {
            DB::table('tenant_user')->insert([
                'tenant_id' => $tenantId,
                'user_id' => $user->id,
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        }

        if ($roleCodes !== []) {
            return $this->syncRoles($tenantId, $actorUserId, $user->id, $roleCodes);
        }

        app(TenantActivityLogService::class)->record(
            $tenantId,
            $actorUserId,
            'team',
            $wasCreated ? 'team.user.created' : 'team.user.attached',
            'user',
            $user->id,
            sprintf('Team user %s %s.', $email, $wasCreated ? 'created' : 'attached'),
            ['email' => $email],
        );

        return $this->detail($tenantId, $user->id);
    }

    public function syncRoles(int $tenantId, int $actorUserId, int $userId, array $roleCodes): array
    {
        $this->ensureTenant($tenantId);
        $this->ensureMembership($tenantId, $userId);

        if ($actorUserId <= 0) {
            throw new HttpException(403, 'Authenticated user missing.');
        }

        $normalizedCodes = $this->normalizeRoleCodes($roleCodes);
        $roles = DB::table('roles')
            ->whereIn('code', $normalizedCodes)
            ->get(['id', 'code']);

        if ($roles->count() !== count($normalizedCodes)) {
            throw new HttpException(422, 'One or more roles are invalid.');
        }

        DB::transaction(function () use ($tenantId, $userId, $roles, $actorUserId): void {
            DB::table('tenant_user_role')
                ->where('tenant_id', $tenantId)
                ->where('user_id', $userId)
                ->delete();

            $rows = $roles->map(fn (object $role) => [
                'tenant_id' => $tenantId,
                'user_id' => $userId,
                'role_id' => $role->id,
                'created_at' => now(),
                'updated_at' => now(),
            ])->all();

            if ($rows !== []) {
                DB::table('tenant_user_role')->insert($rows);
            }

            app(TenantActivityLogService::class)->record(
                $tenantId,
                $actorUserId,
                'team',
                'team.user.roles_synced',
                'user',
                $userId,
                sprintf('Tenant roles synchronized for user %d.', $userId),
                ['role_codes' => $roles->pluck('code')->values()->all()],
            );
        });

        return $this->detail($tenantId, $userId);
    }

    public function detail(int $tenantId, int $userId): array
    {
        $this->ensureTenant($tenantId);
        $this->ensureMembership($tenantId, $userId);

        return collect($this->listUsers($tenantId))
            ->first(fn (array $user) => $user['id'] === $userId)
            ?? throw new HttpException(404, 'Team user not found.');
    }

    private function normalizeRoleCodes(array $roleCodes): array
    {
        return collect($roleCodes)
            ->filter(fn (mixed $roleCode) => is_string($roleCode))
            ->map(fn (string $roleCode) => strtoupper(trim($roleCode)))
            ->filter()
            ->unique()
            ->values()
            ->all();
    }

    private function ensureTenant(int $tenantId): void
    {
        if ($tenantId <= 0) {
            throw new HttpException(403, 'Tenant context is required.');
        }
    }

    private function ensureMembership(int $tenantId, int $userId): void
    {
        $exists = DB::table('tenant_user')
            ->where('tenant_id', $tenantId)
            ->where('user_id', $userId)
            ->exists();

        if (! $exists) {
            throw new HttpException(404, 'Team user not found for tenant.');
        }
    }
}
