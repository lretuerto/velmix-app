<?php

namespace App\Services\Security;

use App\Models\ApiToken;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Symfony\Component\HttpKernel\Exception\HttpException;

class ApiTokenService
{
    public function create(int $tenantId, int $userId, string $name, array $abilities = [], ?string $expiresAt = null): array
    {
        if ($tenantId <= 0 || $userId <= 0) {
            throw new HttpException(403, 'Tenant context or authenticated user missing.');
        }

        $name = trim($name);

        if ($name === '') {
            throw new HttpException(422, 'API token name is required.');
        }

        $abilities = $this->normalizeAbilities($abilities);

        $plainTextToken = Str::random(64);
        $token = ApiToken::query()->create([
            'tenant_id' => $tenantId,
            'user_id' => $userId,
            'name' => $name,
            'token_prefix' => substr($plainTextToken, 0, 12),
            'token_hash' => hash('sha256', $plainTextToken),
            'abilities' => $abilities,
            'expires_at' => $expiresAt !== null ? CarbonImmutable::parse($expiresAt) : null,
        ]);

        app(\App\Services\Audit\TenantActivityLogService::class)->record(
            $tenantId,
            $userId,
            'security',
            'security.api_token.created',
            'api_token',
            $token->id,
            sprintf('API token %s created.', $token->name),
            [
                'token_name' => $token->name,
                'token_prefix' => $token->token_prefix,
            ],
        );

        return $this->serialize($token, $plainTextToken);
    }

    public function listForUser(int $tenantId, int $userId): array
    {
        return ApiToken::query()
            ->where('tenant_id', $tenantId)
            ->where('user_id', $userId)
            ->orderByDesc('id')
            ->get()
            ->map(fn (ApiToken $token) => $this->serialize($token))
            ->all();
    }

    public function revoke(int $tenantId, int $userId, int $tokenId): array
    {
        $token = ApiToken::query()
            ->where('tenant_id', $tenantId)
            ->where('user_id', $userId)
            ->find($tokenId);

        if ($token === null) {
            throw new HttpException(404, 'API token not found.');
        }

        if ($token->revoked_at === null) {
            $token->forceFill([
                'revoked_at' => now(),
            ])->save();

            app(\App\Services\Audit\TenantActivityLogService::class)->record(
                $tenantId,
                $userId,
                'security',
                'security.api_token.revoked',
                'api_token',
                $token->id,
                sprintf('API token %s revoked.', $token->name),
                [
                    'token_name' => $token->name,
                    'token_prefix' => $token->token_prefix,
                ],
            );
        }

        return $this->serialize($token->fresh());
    }

    public function resolveActiveToken(?string $plainTextToken): ?ApiToken
    {
        if ($plainTextToken === null || trim($plainTextToken) === '') {
            return null;
        }

        return ApiToken::query()
            ->where('token_hash', hash('sha256', trim($plainTextToken)))
            ->whereNull('revoked_at')
            ->where(function ($query) {
                $query->whereNull('expires_at')
                    ->orWhere('expires_at', '>', now());
            })
            ->first();
    }

    public function touch(ApiToken $token): void
    {
        DB::table('api_tokens')
            ->where('id', $token->id)
            ->update([
                'last_used_at' => now(),
                'updated_at' => now(),
            ]);
    }

    public function userForToken(ApiToken $token): ?User
    {
        return User::query()->find($token->user_id);
    }

    private function normalizeAbilities(array $abilities): array
    {
        return collect($abilities)
            ->filter(fn (mixed $ability) => is_string($ability))
            ->map(fn (string $ability) => trim($ability))
            ->filter()
            ->unique()
            ->values()
            ->all();
    }

    private function serialize(ApiToken $token, ?string $plainTextToken = null): array
    {
        return [
            'id' => $token->id,
            'tenant_id' => $token->tenant_id,
            'user_id' => $token->user_id,
            'name' => $token->name,
            'token_prefix' => $token->token_prefix,
            'abilities' => $token->abilities ?? [],
            'last_used_at' => $token->last_used_at?->toISOString(),
            'expires_at' => $token->expires_at?->toISOString(),
            'revoked_at' => $token->revoked_at?->toISOString(),
            'plain_text_token' => $plainTextToken,
            'token_type' => $plainTextToken !== null ? 'Bearer' : null,
        ];
    }
}
