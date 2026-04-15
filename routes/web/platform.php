<?php

use App\Services\Admin\TenantTeamService;
use App\Services\Platform\SystemHealthService;
use App\Services\Security\ApiTokenService;
use Illuminate\Support\Facades\Route;

Route::get('/', function () {
    return view('welcome');
});

Route::get('/health/live', function (SystemHealthService $service) {
    return response()->json(['data' => $service->live()]);
});

Route::get('/health/ready', function (SystemHealthService $service) {
    $result = $service->ready();
    $status = $result['status'] === 'ready' ? 200 : 503;

    return response()->json(['data' => $result], $status);
});

Route::post('/team/invitations/accept', function (TenantTeamService $service) {
    $payload = request()->validate([
        'token' => ['required', 'string'],
        'name' => ['nullable', 'string'],
        'password' => ['nullable', 'string', 'min:8'],
    ]);

    $result = $service->acceptInvitation(
        auth()->id() !== null ? (int) auth()->id() : null,
        $payload,
    );

    return response()->json(['data' => $result]);
})->middleware('throttle:team-invitations-accept');

Route::middleware(['auth.session', 'tenant.context', 'tenant.access', 'perm:security.docs.read'])->group(function () {
    Route::get('/docs', function () {
        return response()->json([
            'data' => [
                'project' => 'VELMiX ERP',
                'version' => 'sprint1-day186',
                'documents' => [
                    ['name' => 'OpenAPI YAML', 'path' => '/docs/openapi.yaml'],
                    ['name' => 'API Guide', 'path' => '/docs/api-guide'],
                    ['name' => 'Release Readiness', 'path' => '/docs/release-readiness'],
                ],
                'conventions' => [
                    'Business endpoints support Laravel session auth or Bearer token auth.',
                    'Multi-tenant endpoints require X-Tenant-Id.',
                    'Most responses are wrapped in a data envelope.',
                ],
            ],
        ]);
    });

    Route::get('/docs/openapi.yaml', function () {
        return response(
            file_get_contents(base_path('docs/openapi/velmix.openapi.yaml')),
            200,
            ['Content-Type' => 'application/yaml; charset=UTF-8'],
        );
    });

    Route::get('/docs/api-guide', function () {
        return response(
            file_get_contents(base_path('docs/api-guide.md')),
            200,
            ['Content-Type' => 'text/markdown; charset=UTF-8'],
        );
    });

    Route::get('/docs/release-readiness', function () {
        return response(
            file_get_contents(base_path('docs/sprint1/day90-release-readiness-checklist.md')),
            200,
            ['Content-Type' => 'text/markdown; charset=UTF-8'],
        );
    });
});

Route::middleware(['auth.session', 'tenant.context', 'tenant.access', 'perm:security.api-token.manage', 'throttle:security-api-tokens'])->group(function () {
    Route::get('/auth/tokens', function (ApiTokenService $service) {
        $payload = request()->validate([
            'user_id' => ['nullable', 'integer'],
        ]);

        $result = $service->list(
            (int) request()->attributes->get('tenant_id'),
            isset($payload['user_id']) ? (int) $payload['user_id'] : null,
        );

        return response()->json(['data' => $result]);
    });

    Route::post('/auth/tokens', function (ApiTokenService $service) {
        $payload = request()->validate([
            'name' => ['required', 'string'],
            'abilities' => ['nullable', 'array'],
            'abilities.*' => ['string'],
            'expires_at' => ['nullable', 'date'],
        ]);

        $result = $service->create(
            (int) request()->attributes->get('tenant_id'),
            (int) optional(request()->user())->id,
            (string) $payload['name'],
            $payload['abilities'] ?? [],
            $payload['expires_at'] ?? null,
        );

        return response()->json(['data' => $result]);
    });

    Route::delete('/auth/tokens/{token}', function (int $token, ApiTokenService $service) {
        $result = $service->revoke(
            (int) request()->attributes->get('tenant_id'),
            (int) optional(request()->user())->id,
            $token,
        );

        return response()->json(['data' => $result]);
    });

    Route::post('/auth/tokens/{token}/rotate', function (int $token, ApiTokenService $service) {
        $payload = request()->validate([
            'name' => ['nullable', 'string'],
            'abilities' => ['nullable', 'array'],
            'abilities.*' => ['string'],
            'expires_at' => ['nullable', 'date'],
        ]);

        $result = $service->rotate(
            (int) request()->attributes->get('tenant_id'),
            (int) optional(request()->user())->id,
            $token,
            $payload['name'] ?? null,
            $payload['abilities'] ?? null,
            $payload['expires_at'] ?? null,
        );

        return response()->json(['data' => $result]);
    });
});
