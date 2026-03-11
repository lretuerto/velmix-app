<?php

namespace App\Services\Reports;

use Carbon\CarbonImmutable;
use Symfony\Component\HttpKernel\Exception\HttpException;

class PromiseComplianceReportService
{
    public function __construct(
        private readonly PromiseInsightService $promiseInsights,
    ) {
    }

    public function summary(int $tenantId, ?string $date = null, int $limit = 5): array
    {
        if ($tenantId <= 0) {
            throw new HttpException(403, 'Tenant context is required.');
        }

        if ($limit < 1) {
            throw new HttpException(422, 'limit must be at least 1.');
        }

        $baseDate = $date !== null
            ? CarbonImmutable::createFromFormat('Y-m-d', $date)->startOfDay()
            : CarbonImmutable::now()->startOfDay();

        $receivables = array_values($this->promiseInsights->latestReceivablePromises($tenantId, $baseDate));
        $payables = array_values($this->promiseInsights->latestPayablePromises($tenantId, $baseDate));

        return [
            'tenant_id' => $tenantId,
            'date' => $baseDate->toDateString(),
            'summary' => [
                'receivables' => $this->bucketSummary($receivables),
                'payables' => $this->bucketSummary($payables),
                'combined' => $this->bucketSummary(array_merge($receivables, $payables)),
            ],
            'broken_receivables' => $this->filteredItems($receivables, 'broken', $limit),
            'broken_payables' => $this->filteredItems($payables, 'broken', $limit),
            'pending_receivables' => $this->filteredItems($receivables, 'pending', $limit),
            'pending_payables' => $this->filteredItems($payables, 'pending', $limit),
            'fulfilled_receivables' => $this->filteredItems($receivables, 'fulfilled', $limit),
            'fulfilled_payables' => $this->filteredItems($payables, 'fulfilled', $limit),
        ];
    }

    private function bucketSummary(array $items): array
    {
        $statuses = ['pending', 'fulfilled', 'broken'];
        $summary = [];

        foreach ($statuses as $status) {
            $filtered = array_values(array_filter($items, fn (array $item) => $item['status'] === $status));

            $summary[$status] = [
                'count' => count($filtered),
                'target_amount' => round(array_sum(array_column($filtered, 'target_amount')), 2),
                'outstanding_amount' => round(array_sum(array_column($filtered, 'outstanding_amount')), 2),
            ];
        }

        return $summary;
    }

    private function filteredItems(array $items, string $status, int $limit): array
    {
        $filtered = array_values(array_filter($items, fn (array $item) => $item['status'] === $status));

        if ($status === 'broken') {
            usort($filtered, fn (array $left, array $right) => [$right['days_past_promise'], $right['target_amount'], $right['outstanding_amount']]
                <=> [$left['days_past_promise'], $left['target_amount'], $left['outstanding_amount']]);
        } elseif ($status === 'pending') {
            usort($filtered, function (array $left, array $right) {
                if ($left['days_until_promise'] !== $right['days_until_promise']) {
                    return $left['days_until_promise'] <=> $right['days_until_promise'];
                }

                return $right['target_amount'] <=> $left['target_amount'];
            });
        } else {
            usort($filtered, fn (array $left, array $right) => [$right['resolved_amount'], $right['target_amount']]
                <=> [$left['resolved_amount'], $left['target_amount']]);
        }

        return array_slice($filtered, 0, $limit);
    }
}
