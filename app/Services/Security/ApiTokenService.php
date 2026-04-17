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
    private const DEFAULT_TTL_DAYS = 30;
    private const MAX_TTL_DAYS = 90;

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
        $expiration = $this->resolveExpiration($expiresAt);

        $plainTextToken = Str::random(64);
        $token = ApiToken::query()->create([
            'tenant_id' => $tenantId,
            'user_id' => $userId,
            'name' => $name,
            'token_prefix' => substr($plainTextToken, 0, 12),
            'token_hash' => hash('sha256', $plainTextToken),
            'abilities' => $abilities,
            'expires_at' => $expiration,
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
                'expires_at' => $token->expires_at?->toISOString(),
            ],
        );

        return $this->serialize($token, $plainTextToken);
    }

    public function list(int $tenantId, ?int $userId = null): array
    {
        $query = ApiToken::query()
            ->with('user:id,name,email')
            ->where('tenant_id', $tenantId);

        if ($userId !== null) {
            $query->where('user_id', $userId);
        }

        return $query
            ->orderByDesc('id')
            ->get()
            ->map(fn (ApiToken $token) => $this->serialize($token))
            ->all();
    }

    public function revoke(int $tenantId, int $actorUserId, int $tokenId): array
    {
        $token = ApiToken::query()
            ->where('tenant_id', $tenantId)
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
                $actorUserId,
                'security',
                'security.api_token.revoked',
                'api_token',
                $token->id,
                sprintf('API token %s revoked.', $token->name),
                [
                    'token_name' => $token->name,
                    'token_prefix' => $token->token_prefix,
                    'token_owner_user_id' => $token->user_id,
                ],
            );
        }

        return $this->serialize($token->fresh());
    }

    public function rotate(
        int $tenantId,
        int $actorUserId,
        int $tokenId,
        ?string $name = null,
        ?array $abilities = null,
        ?string $expiresAt = null,
    ): array {
        if ($tenantId <= 0 || $actorUserId <= 0) {
            throw new HttpException(403, 'Tenant context or authenticated user missing.');
        }

        $token = ApiToken::query()
            ->where('tenant_id', $tenantId)
            ->find($tokenId);

        if ($token === null) {
            throw new HttpException(404, 'API token not found.');
        }

        if ($token->revoked_at !== null) {
            throw new HttpException(422, 'Revoked API tokens cannot be rotated.');
        }

        $newName = trim($name ?? $token->name);

        if ($newName === '') {
            throw new HttpException(422, 'API token name is required.');
        }

        $newAbilities = $abilities !== null
            ? $this->normalizeAbilities($abilities)
            : $this->normalizeAbilities($token->abilities ?? []);
        $expiration = $this->resolveExpiration($expiresAt);
        $plainTextToken = Str::random(64);

        $rotatedToken = DB::transaction(function () use (
            $token,
            $tenantId,
            $actorUserId,
            $newName,
            $newAbilities,
            $expiration,
            $plainTextToken,
        ): ApiToken {
            $token->forceFill([
                'revoked_at' => now(),
            ])->save();

            $newToken = ApiToken::query()->create([
                'tenant_id' => $tenantId,
                'user_id' => $token->user_id,
                'name' => $newName,
                'token_prefix' => substr($plainTextToken, 0, 12),
                'token_hash' => hash('sha256', $plainTextToken),
                'abilities' => $newAbilities,
                'expires_at' => $expiration,
            ]);

            app(\App\Services\Audit\TenantActivityLogService::class)->record(
                $tenantId,
                $actorUserId,
                'security',
                'security.api_token.rotated',
                'api_token',
                $newToken->id,
                sprintf('API token %s rotated.', $newToken->name),
                [
                    'token_name' => $newToken->name,
                    'token_prefix' => $newToken->token_prefix,
                    'rotated_from_token_id' => $token->id,
                    'rotated_from_token_prefix' => $token->token_prefix,
                    'token_owner_user_id' => $token->user_id,
                    'expires_at' => $newToken->expires_at?->toISOString(),
                ],
            );

            return $newToken;
        });

        return $this->serialize($rotatedToken, $plainTextToken);
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

    private function resolveExpiration(?string $expiresAt): CarbonImmutable
    {
        $reference = CarbonImmutable::now();
        $expiration = $expiresAt !== null
            ? CarbonImmutable::parse($expiresAt)->endOfDay()
            : $reference->addDays(self::DEFAULT_TTL_DAYS)->endOfDay();

        if ($expiration->lte($reference)) {
            throw new HttpException(422, 'API token expiration must be in the future.');
        }

        if ($expiration->gt($reference->addDays(self::MAX_TTL_DAYS)->endOfDay())) {
            throw new HttpException(422, sprintf('API token expiration cannot exceed %d days.', self::MAX_TTL_DAYS));
        }

        return $expiration;
    }

    private function serialize(ApiToken $token, ?string $plainTextToken = null): array
    {
        $user = $token->relationLoaded('user') ? $token->user : $token->user()->first();

        return [
            'id' => $token->id,
            'tenant_id' => $token->tenant_id,
            'user_id' => $token->user_id,
            'name' => $token->name,
            'token_prefix' => $token->token_prefix,
            'abilities' => $token->abilities ?? [],
            'status' => $token->revoked_at !== null
                ? 'revoked'
                : ($token->expires_at !== null && $token->expires_at->lte(now()) ? 'expired' : 'active'),
            'last_used_at' => $token->last_used_at?->toISOString(),
            'expires_at' => $token->expires_at?->toISOString(),
            'revoked_at' => $token->revoked_at?->toISOString(),
            'owner' => $user !== null ? [
                'id' => (int) $user->id,
                'name' => (string) $user->name,
                'email' => (string) $user->email,
            ] : null,
            'plain_text_token' => $plainTextToken,
            'token_type' => $plainTextToken !== null ? 'Bearer' : null,
        ];
    }
}
