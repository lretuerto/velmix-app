<?php

namespace App\Services\Admin;

use App\Models\TenantUserInvitation;
use App\Models\User;
use App\Services\Audit\TenantActivityLogService;
use Illuminate\Support\Carbon;
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

    public function listInvitations(int $tenantId): array
    {
        $this->ensureTenant($tenantId);
        $this->expirePendingInvitations($tenantId);

        return TenantUserInvitation::query()
            ->where('tenant_id', $tenantId)
            ->orderByDesc('id')
            ->get()
            ->map(fn (TenantUserInvitation $invitation) => $this->serializeInvitation($invitation))
            ->all();
    }

    public function inviteUser(int $tenantId, int $actorUserId, array $payload): array
    {
        $this->ensureTenant($tenantId);

        if ($actorUserId <= 0) {
            throw new HttpException(403, 'Authenticated user missing.');
        }

        $email = strtolower(trim((string) ($payload['email'] ?? '')));
        $name = trim((string) ($payload['name'] ?? ''));
        $roleCodes = $this->normalizeRoleCodes($payload['roles'] ?? []);
        $expiresAt = $payload['expires_at'] ?? null;

        if ($email === '') {
            throw new HttpException(422, 'Invitation email is required.');
        }

        if ($this->isTenantMember($tenantId, $email)) {
            throw new HttpException(409, 'User is already a member of this tenant.');
        }

        $resolvedRoles = $this->resolveRoleCodes($roleCodes);
        $this->expirePendingInvitations($tenantId, $email);

        $expiresAtValue = $this->resolveInvitationExpiry($expiresAt);
        $plainTextToken = bin2hex(random_bytes(24));

        try {
            $invitation = TenantUserInvitation::query()->create([
                'tenant_id' => $tenantId,
                'email' => $email,
                'name' => $name !== '' ? $name : null,
                'invited_by_user_id' => $actorUserId,
                'accepted_by_user_id' => null,
                'status' => 'pending',
                'pending_guard' => $email,
                'token_hash' => hash('sha256', $plainTextToken),
                'role_codes' => $resolvedRoles,
                'expires_at' => $expiresAtValue,
                'accepted_at' => null,
                'revoked_at' => null,
                'revoke_reason' => null,
            ]);
        } catch (\Illuminate\Database\UniqueConstraintViolationException) {
            throw new HttpException(409, 'A pending invitation already exists for this email in the tenant.');
        }

        app(TenantActivityLogService::class)->record(
            $tenantId,
            $actorUserId,
            'team',
            'team.invitation.created',
            'tenant_user_invitation',
            $invitation->id,
            sprintf('Invitation created for %s.', $email),
            [
                'email' => $email,
                'role_codes' => $resolvedRoles,
                'expires_at' => $invitation->expires_at?->toIso8601String(),
            ],
        );

        return array_merge(
            $this->serializeInvitation($invitation),
            ['plain_text_token' => $plainTextToken],
        );
    }

    public function revokeInvitation(int $tenantId, int $actorUserId, int $invitationId, string $reason): array
    {
        $this->ensureTenant($tenantId);

        if ($actorUserId <= 0) {
            throw new HttpException(403, 'Authenticated user missing.');
        }

        if (trim($reason) === '') {
            throw new HttpException(422, 'Revoke reason is required.');
        }

        return DB::transaction(function () use ($tenantId, $actorUserId, $invitationId, $reason): array {
            $invitation = TenantUserInvitation::query()
                ->where('tenant_id', $tenantId)
                ->where('id', $invitationId)
                ->lockForUpdate()
                ->first();

            if ($invitation === null) {
                throw new HttpException(404, 'Invitation not found.');
            }

            $this->expireInvitationIfNeeded($invitation);

            if ($invitation->status === 'expired') {
                throw new HttpException(422, 'Invitation expired.');
            }

            if ($invitation->status !== 'pending') {
                throw new HttpException(422, 'Only pending invitations can be revoked.');
            }

            $invitation->forceFill([
                'status' => 'revoked',
                'pending_guard' => null,
                'revoked_at' => now(),
                'revoke_reason' => trim($reason),
            ])->save();

            app(TenantActivityLogService::class)->record(
                $tenantId,
                $actorUserId,
                'team',
                'team.invitation.revoked',
                'tenant_user_invitation',
                $invitation->id,
                sprintf('Invitation revoked for %s.', $invitation->email),
                ['reason' => trim($reason)],
            );

            return $this->serializeInvitation($invitation->fresh());
        });
    }

    public function acceptInvitation(?int $actorUserId, array $payload): array
    {
        $plainTextToken = trim((string) ($payload['token'] ?? ''));
        $name = trim((string) ($payload['name'] ?? ''));
        $password = (string) ($payload['password'] ?? '');

        if ($plainTextToken === '') {
            throw new HttpException(422, 'Invitation token is required.');
        }

        return DB::transaction(function () use ($actorUserId, $plainTextToken, $name, $password): array {
            $invitation = TenantUserInvitation::query()
                ->where('token_hash', hash('sha256', $plainTextToken))
                ->lockForUpdate()
                ->first();

            if ($invitation === null) {
                throw new HttpException(404, 'Invitation not found.');
            }

            $this->expireInvitationIfNeeded($invitation);

            if ($invitation->status === 'expired') {
                throw new HttpException(422, 'Invitation expired.');
            }

            if ($invitation->status !== 'pending') {
                throw new HttpException(422, 'Invitation is not pending.');
            }

            $tenantId = (int) $invitation->tenant_id;
            $email = strtolower((string) $invitation->email);
            $user = User::query()->whereRaw('lower(email) = ?', [$email])->first();
            $wasCreated = false;

            if ($user !== null) {
                if ($actorUserId === null || $actorUserId <= 0 || $actorUserId !== (int) $user->id) {
                    throw new HttpException(
                        409,
                        'Invitation for an existing user requires an authenticated session for the invited email.',
                    );
                }
            } else {
                if ($name === '') {
                    throw new HttpException(422, 'Name is required to accept invitation for a new user.');
                }

                if (strlen($password) < 8) {
                    throw new HttpException(422, 'Password is required with at least 8 characters for a new user.');
                }

                $user = User::query()->create([
                    'name' => $name,
                    'email' => $email,
                    'password' => Hash::make($password),
                    'email_verified_at' => now(),
                ]);
                $wasCreated = true;
            }

            if ($this->isTenantMemberByUserId($tenantId, (int) $user->id)) {
                throw new HttpException(409, 'Invited user is already a member of this tenant.');
            }

            DB::table('tenant_user')->insert([
                'tenant_id' => $tenantId,
                'user_id' => $user->id,
                'created_at' => now(),
                'updated_at' => now(),
            ]);

            $this->syncMembershipRoles($tenantId, (int) $user->id, $this->normalizeRoleCodes($invitation->role_codes ?? []));

            $invitation->forceFill([
                'status' => 'accepted',
                'pending_guard' => null,
                'accepted_by_user_id' => $user->id,
                'accepted_at' => now(),
            ])->save();

            app(TenantActivityLogService::class)->record(
                $tenantId,
                (int) $user->id,
                'team',
                'team.invitation.accepted',
                'tenant_user_invitation',
                $invitation->id,
                sprintf('Invitation accepted for %s.', $email),
                [
                    'user_id' => (int) $user->id,
                    'was_created' => $wasCreated,
                    'role_codes' => $this->normalizeRoleCodes($invitation->role_codes ?? []),
                ],
            );

            return [
                'invitation' => $this->serializeInvitation($invitation->fresh()),
                'user' => $this->detail($tenantId, (int) $user->id),
            ];
        });
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
        } elseif ($this->belongsToOtherTenant($tenantId, $user->id)) {
            throw new HttpException(
                409,
                'Existing users from another tenant cannot be attached directly. Use a new email or an invitation flow.',
            );
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
        if (! $this->isTenantMemberByUserId($tenantId, $userId)) {
            throw new HttpException(404, 'Team user not found for tenant.');
        }
    }

    private function belongsToOtherTenant(int $tenantId, int $userId): bool
    {
        return DB::table('tenant_user')
            ->where('user_id', $userId)
            ->where('tenant_id', '!=', $tenantId)
            ->exists();
    }

    private function resolveRoleCodes(array $roleCodes): array
    {
        $normalizedCodes = $this->normalizeRoleCodes($roleCodes);

        if ($normalizedCodes === []) {
            return [];
        }

        $roles = DB::table('roles')
            ->whereIn('code', $normalizedCodes)
            ->pluck('code')
            ->map(fn (string $code) => (string) $code)
            ->all();

        sort($roles);
        sort($normalizedCodes);

        if ($roles !== $normalizedCodes) {
            throw new HttpException(422, 'One or more roles are invalid.');
        }

        return $normalizedCodes;
    }

    private function resolveInvitationExpiry(?string $expiresAt): Carbon
    {
        $value = $expiresAt !== null
            ? Carbon::parse($expiresAt)->endOfDay()
            : now()->addDays(7)->endOfDay();

        if ($value->isPast()) {
            throw new HttpException(422, 'Invitation expiry must be in the future.');
        }

        if ($value->greaterThan(now()->addDays(30)->endOfDay())) {
            throw new HttpException(422, 'Invitation expiry cannot be greater than 30 days.');
        }

        return $value;
    }

    private function expirePendingInvitations(int $tenantId, ?string $email = null): void
    {
        $query = TenantUserInvitation::query()
            ->where('tenant_id', $tenantId)
            ->where('status', 'pending')
            ->where('expires_at', '<', now());

        if ($email !== null) {
            $query->where('pending_guard', strtolower(trim($email)));
        }

        $query->update([
            'status' => 'expired',
            'pending_guard' => null,
            'updated_at' => now(),
        ]);
    }

    private function expireInvitationIfNeeded(TenantUserInvitation $invitation): void
    {
        if ($invitation->status === 'pending' && $invitation->expires_at !== null && $invitation->expires_at->isPast()) {
            $invitation->forceFill([
                'status' => 'expired',
                'pending_guard' => null,
            ])->save();
        }
    }

    private function isTenantMember(int $tenantId, string $email): bool
    {
        return DB::table('tenant_user')
            ->join('users', 'users.id', '=', 'tenant_user.user_id')
            ->where('tenant_user.tenant_id', $tenantId)
            ->whereRaw('lower(users.email) = ?', [strtolower(trim($email))])
            ->exists();
    }

    private function isTenantMemberByUserId(int $tenantId, int $userId): bool
    {
        return DB::table('tenant_user')
            ->where('tenant_id', $tenantId)
            ->where('user_id', $userId)
            ->exists();
    }

    private function syncMembershipRoles(int $tenantId, int $userId, array $roleCodes): void
    {
        if ($roleCodes === []) {
            return;
        }

        $roleIds = DB::table('roles')
            ->whereIn('code', $roleCodes)
            ->pluck('id')
            ->all();

        $rows = collect($roleIds)->map(fn (int $roleId) => [
            'tenant_id' => $tenantId,
            'user_id' => $userId,
            'role_id' => $roleId,
            'created_at' => now(),
            'updated_at' => now(),
        ])->all();

        DB::table('tenant_user_role')->insert($rows);
    }

    private function serializeInvitation(TenantUserInvitation $invitation): array
    {
        return [
            'id' => (int) $invitation->id,
            'tenant_id' => (int) $invitation->tenant_id,
            'email' => (string) $invitation->email,
            'name' => $invitation->name !== null ? (string) $invitation->name : null,
            'status' => (string) $invitation->status,
            'role_codes' => $this->normalizeRoleCodes($invitation->role_codes ?? []),
            'expires_at' => $invitation->expires_at?->toIso8601String(),
            'accepted_at' => $invitation->accepted_at?->toIso8601String(),
            'revoked_at' => $invitation->revoked_at?->toIso8601String(),
            'revoke_reason' => $invitation->revoke_reason,
            'invited_by_user_id' => (int) $invitation->invited_by_user_id,
            'accepted_by_user_id' => $invitation->accepted_by_user_id !== null
                ? (int) $invitation->accepted_by_user_id
                : null,
            'created_at' => $invitation->created_at?->toIso8601String(),
        ];
    }
}
