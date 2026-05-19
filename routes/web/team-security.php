<?php

use App\Services\Admin\TenantTeamService;
use Illuminate\Support\Facades\Route;

Route::middleware(['auth.hybrid', 'tenant.context', 'tenant.access'])->group(function () {
    Route::get('/tenant/ping', function () {
        return response()->json([
            'ok' => true,
            'tenant' => app('currentTenantId'),
            'auth_mode' => (string) request()->attributes->get('auth_mode', 'session'),
        ]);
    })->middleware('perm:security.context.read');

    Route::get('/auth/me', function () {
        $user = request()->user();

        return response()->json([
            'data' => [
                'user' => [
                    'id' => (int) $user->id,
                    'name' => (string) $user->name,
                    'email' => (string) $user->email,
                ],
                'tenant_id' => (int) request()->attributes->get('tenant_id'),
                'auth_mode' => (string) request()->attributes->get('auth_mode', 'session'),
                'api_token_id' => request()->attributes->get('api_token_id'),
            ],
        ]);
    })->middleware('perm:security.context.read');

    Route::get('/admin/team/roles', function (TenantTeamService $service) {
        return response()->json(['data' => $service->listRoles()]);
    })->middleware('perm:team.user.read');

    Route::get('/admin/team/users', function (TenantTeamService $service) {
        return response()->json([
            'data' => $service->listUsers((int) request()->attributes->get('tenant_id')),
        ]);
    })->middleware('perm:team.user.read');

    Route::get('/admin/team/invitations', function (TenantTeamService $service) {
        return response()->json([
            'data' => $service->listInvitations((int) request()->attributes->get('tenant_id')),
        ]);
    })->middleware('perm:team.invitation.read');

    Route::post('/admin/team/invitations', function (TenantTeamService $service) {
        $payload = request()->validate([
            'email' => ['required', 'email'],
            'name' => ['nullable', 'string'],
            'roles' => ['nullable', 'array'],
            'roles.*' => ['string'],
            'expires_at' => ['nullable', 'date'],
        ]);

        $result = $service->inviteUser(
            (int) request()->attributes->get('tenant_id'),
            (int) optional(request()->user())->id,
            $payload,
        );

        return response()->json(['data' => $result]);
    })->middleware('perm:team.invitation.manage');

    Route::post('/admin/team/invitations/{invitation}/revoke', function (int $invitation, TenantTeamService $service) {
        $payload = request()->validate([
            'reason' => ['required', 'string'],
        ]);

        $result = $service->revokeInvitation(
            (int) request()->attributes->get('tenant_id'),
            (int) optional(request()->user())->id,
            $invitation,
            (string) $payload['reason'],
        );

        return response()->json(['data' => $result]);
    })->middleware('perm:team.invitation.manage');

    Route::post('/admin/team/users', function (TenantTeamService $service) {
        $payload = request()->validate([
            'name' => ['required', 'string'],
            'email' => ['required', 'email'],
            'password' => ['nullable', 'string', 'min:8'],
            'roles' => ['nullable', 'array'],
            'roles.*' => ['string'],
        ]);

        $result = $service->createOrAttachUser(
            (int) request()->attributes->get('tenant_id'),
            (int) optional(request()->user())->id,
            $payload,
        );

        return response()->json(['data' => $result]);
    })->middleware('perm:team.user.manage');

    Route::post('/admin/team/users/{user}/roles', function (int $user, TenantTeamService $service) {
        $payload = request()->validate([
            'roles' => ['required', 'array'],
            'roles.*' => ['string'],
        ]);

        $result = $service->syncRoles(
            (int) request()->attributes->get('tenant_id'),
            (int) optional(request()->user())->id,
            $user,
            $payload['roles'],
        );

        return response()->json(['data' => $result]);
    })->middleware(['perm:team.user.manage', 'perm:rbac.role.assign']);
});
