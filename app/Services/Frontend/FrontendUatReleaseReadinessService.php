<?php

namespace App\Services\Frontend;

use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\File;

class FrontendUatReleaseReadinessService
{
    public function summary(int $freshnessHours = 24): array
    {
        $freshnessHours = max(1, $freshnessHours);
        $paths = $this->artifactPaths();
        $smoke = $this->loadJson($paths['smoke_evidence_path']);
        $packet = $this->loadJson($paths['signoff_packet_path']);
        $visualSignoff = $this->loadJson($paths['visual_signoff_path']);
        $items = array_merge(
            $this->smokeItems($smoke, $paths['smoke_evidence_path'], $freshnessHours),
            $this->packetItems($packet, $paths['signoff_packet_path'], $freshnessHours),
            $this->visualSignoffItems($visualSignoff, $paths['visual_signoff_path'], $paths['signoff_packet_path'], $freshnessHours),
        );

        return [
            'status' => $items === [] ? 'ready_for_release' : 'blocked',
            'checked_at' => now()->toISOString(),
            'freshness_hours' => $freshnessHours,
            'artifacts' => $paths,
            'evidence' => [
                'smoke' => $this->evidenceSummary($smoke, 'checked_at'),
                'signoff_packet' => $this->evidenceSummary($packet, 'generated_at'),
                'visual_signoff' => $this->evidenceSummary($visualSignoff, 'verified_at'),
            ],
            'items' => $items,
        ];
    }

    /**
     * @return array<string, string>
     */
    private function artifactPaths(): array
    {
        $baseDirectory = FrontendUatArtifactPaths::baseDirectory();

        return [
            'smoke_evidence_path' => $baseDirectory.'/pos-quote-first-smoke-latest.json',
            'signoff_packet_path' => $baseDirectory.'/signoff/frontend-uat-signoff-latest.json',
            'visual_evidence_path' => $baseDirectory.'/signoff/frontend-uat-visual-evidence-latest.json',
            'visual_signoff_path' => $baseDirectory.'/signoff/frontend-uat-visual-signoff-latest.json',
        ];
    }

    /**
     * @return array<string, mixed>|null
     */
    private function loadJson(string $path): ?array
    {
        if (! is_file($path)) {
            return null;
        }

        $decoded = json_decode(File::get($path), true);

        return is_array($decoded) ? $decoded : [
            'status' => 'invalid',
            'reason' => 'Artifact is not valid JSON.',
        ];
    }

    /**
     * @param  array<string, mixed>|null  $smoke
     * @return array<int, array<string, mixed>>
     */
    private function smokeItems(?array $smoke, string $path, int $freshnessHours): array
    {
        if ($smoke === null) {
            return [$this->item(
                'frontend_uat_smoke_missing',
                'POS quote-first smoke evidence is missing.',
                'Run php artisan frontend:pos-quote-first-uat-smoke --json before release readiness.',
                $path,
            )];
        }

        $items = [];

        if (($smoke['status'] ?? null) !== 'passed') {
            $items[] = $this->item(
                'frontend_uat_smoke_not_passed',
                'POS quote-first smoke evidence is not passed.',
                'Fix the smoke findings and regenerate POS quote-first smoke evidence.',
                $path,
            );
        }

        return array_merge($items, $this->freshnessItems(
            'frontend_uat_smoke_stale',
            'POS quote-first smoke evidence is stale.',
            $smoke['checked_at'] ?? null,
            $freshnessHours,
            $path,
        ));
    }

    /**
     * @param  array<string, mixed>|null  $packet
     * @return array<int, array<string, mixed>>
     */
    private function packetItems(?array $packet, string $path, int $freshnessHours): array
    {
        if ($packet === null) {
            return [$this->item(
                'frontend_uat_signoff_packet_missing',
                'Frontend UAT signoff packet is missing.',
                'Run php artisan frontend:uat-signoff-pack --json before release readiness.',
                $path,
            )];
        }

        $items = [];

        if (($packet['status'] ?? null) !== 'ready_for_visual_signoff') {
            $items[] = $this->item(
                'frontend_uat_signoff_packet_not_ready',
                'Frontend UAT signoff packet is not ready_for_visual_signoff.',
                'Resolve blocked signoff packet items before release readiness.',
                $path,
            );
        }

        if (($packet['blocked_items'] ?? []) !== []) {
            $items[] = $this->item(
                'frontend_uat_signoff_packet_has_blockers',
                'Frontend UAT signoff packet still contains blockers.',
                'Resolve packet blockers and regenerate the signoff packet.',
                $path,
                [
                    'blocked_count' => count($packet['blocked_items'] ?? []),
                ],
            );
        }

        return array_merge($items, $this->freshnessItems(
            'frontend_uat_signoff_packet_stale',
            'Frontend UAT signoff packet is stale.',
            $packet['generated_at'] ?? null,
            $freshnessHours,
            $path,
        ));
    }

    /**
     * @param  array<string, mixed>|null  $visualSignoff
     * @return array<int, array<string, mixed>>
     */
    private function visualSignoffItems(?array $visualSignoff, string $path, string $packetPath, int $freshnessHours): array
    {
        if ($visualSignoff === null) {
            return [$this->item(
                'frontend_uat_visual_signoff_missing',
                'Frontend UAT visual signoff verification is missing.',
                'Complete visual evidence and run php artisan frontend:uat-visual-evidence-verify --json.',
                $path,
            )];
        }

        $items = [];

        if (($visualSignoff['status'] ?? null) !== 'signed') {
            $items[] = $this->item(
                'frontend_uat_visual_signoff_not_signed',
                'Frontend UAT visual signoff is not signed.',
                'Complete module evidence and final approvals before release readiness.',
                $path,
            );
        }

        if (($visualSignoff['blocked_items'] ?? []) !== []) {
            $items[] = $this->item(
                'frontend_uat_visual_signoff_has_blockers',
                'Frontend UAT visual signoff still contains blockers.',
                'Resolve all visual signoff blockers before release readiness.',
                $path,
                [
                    'blocked_count' => count($visualSignoff['blocked_items'] ?? []),
                ],
            );
        }

        if (trim((string) ($visualSignoff['packet_path'] ?? '')) !== trim($packetPath)) {
            $items[] = $this->item(
                'frontend_uat_visual_signoff_packet_mismatch',
                'Frontend UAT visual signoff does not point to the latest signoff packet.',
                'Regenerate visual evidence from the latest packet and verify it again.',
                $path,
                [
                    'expected_packet_path' => $packetPath,
                    'actual_packet_path' => $visualSignoff['packet_path'] ?? null,
                ],
            );
        }

        if ((int) ($visualSignoff['module_count'] ?? 0) < 1 || (int) ($visualSignoff['approval_count'] ?? 0) < 1) {
            $items[] = $this->item(
                'frontend_uat_visual_signoff_empty',
                'Frontend UAT visual signoff does not include modules and approvals.',
                'Verify a completed visual evidence manifest with module and final approval evidence.',
                $path,
            );
        }

        return array_merge($items, $this->freshnessItems(
            'frontend_uat_visual_signoff_stale',
            'Frontend UAT visual signoff verification is stale.',
            $visualSignoff['verified_at'] ?? null,
            $freshnessHours,
            $path,
        ));
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private function freshnessItems(string $code, string $message, mixed $timestamp, int $freshnessHours, string $path): array
    {
        if (! is_string($timestamp) || trim($timestamp) === '') {
            return [$this->item(
                $code,
                $message,
                'Regenerate this artifact so it includes a valid timestamp.',
                $path,
            )];
        }

        try {
            $recordedAt = Carbon::parse($timestamp);
        } catch (\Throwable) {
            return [$this->item(
                $code,
                $message,
                'Regenerate this artifact so it includes a parseable timestamp.',
                $path,
                [
                    'timestamp' => $timestamp,
                ],
            )];
        }

        if ($recordedAt->lt(now()->subHours($freshnessHours))) {
            return [$this->item(
                $code,
                $message,
                'Refresh frontend UAT evidence inside the configured freshness window.',
                $path,
                [
                    'recorded_at' => $recordedAt->toISOString(),
                    'freshness_hours' => $freshnessHours,
                ],
            )];
        }

        return [];
    }

    /**
     * @param  array<string, mixed>|null  $evidence
     * @return array<string, mixed>
     */
    private function evidenceSummary(?array $evidence, string $timestampField): array
    {
        if ($evidence === null) {
            return [
                'status' => 'missing',
                'timestamp' => null,
            ];
        }

        return [
            'status' => $evidence['status'] ?? 'unknown',
            'timestamp' => $evidence[$timestampField] ?? null,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function item(string $code, string $message, string $action, string $path, array $metricSnapshot = []): array
    {
        $item = [
            'severity' => 'critical',
            'code' => $code,
            'message' => $message,
            'action' => $action,
            'path' => $path,
        ];

        if ($metricSnapshot !== []) {
            $item['metric_snapshot'] = $metricSnapshot;
        }

        return $item;
    }
}
