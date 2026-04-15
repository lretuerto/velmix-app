<?php

namespace App\Services\Platform;

use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

class OperationalDataPruneService
{
    public function prune(bool $pretend = false): array
    {
        $retention = config('velmix.retention');
        $now = CarbonImmutable::now();

        $targets = [
            'idempotency_keys' => [
                'cutoff' => $now->subDays((int) $retention['idempotency_days']),
                'query' => fn () => DB::table('idempotency_keys')
                    ->where('created_at', '<', $now->subDays((int) $retention['idempotency_days']))
                    ->where('status', '!=', 'in_progress'),
            ],
            'tenant_user_invitations' => [
                'cutoff' => $now->subDays((int) $retention['team_invitations_days']),
                'query' => fn () => DB::table('tenant_user_invitations')
                    ->where('updated_at', '<', $now->subDays((int) $retention['team_invitations_days']))
                    ->whereIn('status', ['accepted', 'revoked', 'expired']),
            ],
            'operations_control_tower_snapshots' => [
                'cutoff' => $now->subDays((int) $retention['control_tower_snapshots_days']),
                'query' => fn () => DB::table('operations_control_tower_snapshots')
                    ->where('created_at', '<', $now->subDays((int) $retention['control_tower_snapshots_days'])),
            ],
        ];

        $items = [];

        $collectItems = function () use (&$items, $pretend, $targets): void {
            foreach ($targets as $table => $target) {
                $query = $target['query']();
                $count = (clone $query)->count();

                if (! $pretend && $count > 0) {
                    $query->delete();
                }

                $items[] = [
                    'table' => $table,
                    'pretend' => $pretend,
                    'count' => $count,
                    'cutoff' => $target['cutoff']->toIso8601String(),
                ];
            }
        };

        if ($pretend) {
            $collectItems();
        } else {
            DB::transaction(function () use ($collectItems): void {
                $collectItems();
            });
        }

        return [
            'pretend' => $pretend,
            'checked_at' => $now->toIso8601String(),
            'total_pruned_count' => array_sum(array_map(fn (array $item) => $item['count'], $items)),
            'items' => $items,
        ];
    }
}
