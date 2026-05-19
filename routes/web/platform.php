<?php

use App\Services\Admin\TenantTeamService;
use App\Services\Frontend\AppShellBootstrapService;
use App\Services\Platform\SystemHealthService;
use App\Services\Security\ApiTokenService;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Route;
use Illuminate\Validation\ValidationException;

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

Route::get('/app/{any?}', function (AppShellBootstrapService $bootstrap) {
    $boot = $bootstrap->build(
        request()->user() ?? auth()->user(),
        request()->query('tenant'),
        (string) request()->attributes->get('request_id', ''),
    );

    return view('app', ['boot' => $boot]);
})->where('any', '.*');

Route::post('/auth/session/login', function (AppShellBootstrapService $bootstrap) {
    $payload = request()->validate([
        'email' => ['required', 'email'],
        'password' => ['required', 'string'],
        'tenant' => ['nullable', 'string'],
    ]);
    $email = trim((string) $payload['email']);
    $emailFingerprint = sha1(strtolower($email));
    $tenantSelector = isset($payload['tenant']) && trim((string) $payload['tenant']) !== ''
        ? trim((string) $payload['tenant'])
        : null;
    $requestId = (string) request()->attributes->get('request_id', '');

    if (! Auth::attempt([
        'email' => $email,
        'password' => (string) $payload['password'],
    ])) {
        Log::warning('frontend.session_login_failed', [
            'email_sha1' => $emailFingerprint,
            'tenant_selector' => $tenantSelector,
            'request_id' => $requestId,
            'ip' => request()->ip(),
        ]);

        throw ValidationException::withMessages([
            'email' => ['Las credenciales no son validas.'],
        ]);
    }

    request()->session()->regenerate();
    $boot = $bootstrap->build(
        request()->user() ?? auth()->user(),
        $tenantSelector,
        $requestId,
    );

    Log::info('frontend.session_login_succeeded', [
        'user_id' => (int) auth()->id(),
        'tenant_selector' => $tenantSelector,
        'selected_tenant_id' => $boot['tenant']['selected']['id'] ?? null,
        'selection_error' => $boot['tenant']['selection_error'] ?? null,
        'request_id' => $requestId,
        'ip' => request()->ip(),
    ]);

    return response()->json([
        'data' => $boot,
    ]);
})->middleware('throttle:frontend-session-login');

Route::post('/auth/session/logout', function () {
    Log::info('frontend.session_logout', [
        'user_id' => (int) auth()->id(),
        'request_id' => (string) request()->attributes->get('request_id', ''),
        'ip' => request()->ip(),
    ]);

    Auth::guard('web')->logout();

    request()->session()->invalidate();
    request()->session()->regenerateToken();

    return response()->json([
        'data' => [
            'status' => 'logged_out',
        ],
    ]);
})->middleware('auth.session');

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
                'version' => 'sprint1-day187',
                'documents' => [
                    ['name' => 'OpenAPI YAML', 'path' => '/docs/openapi.yaml'],
                    ['name' => 'API Guide', 'path' => '/docs/api-guide'],
                    ['name' => 'Release Readiness', 'path' => '/docs/release-readiness'],
                    ['name' => 'Backend Operations Runbook', 'path' => '/docs/operations-runbook'],
                    ['name' => 'Deployment And Rollback Runbook', 'path' => '/docs/deployment-rollback'],
                    ['name' => 'Backup And Restore Runbook', 'path' => '/docs/backup-restore'],
                    ['name' => 'Staging Certification Runbook', 'path' => '/docs/staging-certification'],
                    ['name' => 'Release Promotion Runbook', 'path' => '/docs/release-promotion'],
                    ['name' => 'Release Cutover Runbook', 'path' => '/docs/release-cutover'],
                    ['name' => 'Operational Certification Runbook', 'path' => '/docs/operational-certification'],
                    ['name' => 'Evidence Governed Deploy Workflow', 'path' => '/docs/evidence-governed-deploy'],
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

    Route::get('/docs/operations-runbook', function () {
        return response(
            file_get_contents(base_path('docs/operations/backend-operations-runbook.md')),
            200,
            ['Content-Type' => 'text/markdown; charset=UTF-8'],
        );
    });

    Route::get('/docs/deployment-rollback', function () {
        return response(
            file_get_contents(base_path('docs/operations/deployment-and-rollback-runbook.md')),
            200,
            ['Content-Type' => 'text/markdown; charset=UTF-8'],
        );
    });

    Route::get('/docs/backup-restore', function () {
        return response(
            file_get_contents(base_path('docs/operations/backup-and-restore-runbook.md')),
            200,
            ['Content-Type' => 'text/markdown; charset=UTF-8'],
        );
    });

    Route::get('/docs/staging-certification', function () {
        return response(
            file_get_contents(base_path('docs/operations/staging-certification-runbook.md')),
            200,
            ['Content-Type' => 'text/markdown; charset=UTF-8'],
        );
    });

    Route::get('/docs/release-promotion', function () {
        return response(
            file_get_contents(base_path('docs/operations/release-promotion-runbook.md')),
            200,
            ['Content-Type' => 'text/markdown; charset=UTF-8'],
        );
    });

    Route::get('/docs/release-cutover', function () {
        return response(
            file_get_contents(base_path('docs/operations/release-cutover-runbook.md')),
            200,
            ['Content-Type' => 'text/markdown; charset=UTF-8'],
        );
    });

    Route::get('/docs/operational-certification', function () {
        return response(
            file_get_contents(base_path('docs/operations/operational-certification-runbook.md')),
            200,
            ['Content-Type' => 'text/markdown; charset=UTF-8'],
        );
    });

    Route::get('/docs/evidence-governed-deploy', function () {
        return response(
            file_get_contents(base_path('docs/operations/evidence-governed-deployment-workflow.md')),
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
