<?php

namespace App\Services\Frontend;

use App\Services\Platform\SystemObservabilityReportService;
use App\Services\Platform\SystemPreflightService;
use Illuminate\Support\Facades\File;
use RuntimeException;

class FrontendUatReleaseClosureService
{
    public function __construct(
        private readonly FrontendUatReleaseReadinessService $readiness,
        private readonly SystemPreflightService $preflight,
        private readonly SystemObservabilityReportService $observability,
    ) {}

    /**
     * @param  array{owner?: string, decided_at?: string, ticket?: string, notes?: string}  $decision
     * @return array<string, mixed>
     */
    public function build(
        int $freshnessHours = 24,
        bool $allowGateDisabled = false,
        bool $allowObservabilityCritical = false,
        array $decision = [],
    ): array {
        $freshnessHours = max(1, $freshnessHours);
        $readiness = $this->readiness->summary($freshnessHours);
        $preflight = $this->preflight->summary();
        $observability = $this->observability->summary();
        $frontendGate = (array) ($preflight['checks']['frontend_uat_release_gate'] ?? []);
        $items = $this->blockingItems(
            $readiness,
            $preflight,
            $observability,
            $allowGateDisabled,
            $allowObservabilityCritical,
        );
        $status = $items === [] ? 'ready_for_release_closure' : 'blocked';

        $closure = [
            'status' => $status,
            'generated_at' => now()->toISOString(),
            'freshness_hours' => $freshnessHours,
            'allow_gate_disabled' => $allowGateDisabled,
            'allow_observability_critical' => $allowObservabilityCritical,
            'environment' => app()->environment(),
            'frontend_uat_release_readiness' => [
                'status' => $readiness['status'] ?? 'blocked',
                'checked_at' => $readiness['checked_at'] ?? null,
                'evidence' => $readiness['evidence'] ?? [],
                'artifacts' => $readiness['artifacts'] ?? [],
                'item_count' => count($readiness['items'] ?? []),
            ],
            'system_preflight' => [
                'status' => $preflight['status'] ?? 'unknown',
                'checked_at' => $preflight['checked_at'] ?? null,
                'item_count' => count($preflight['items'] ?? []),
                'frontend_uat_release_gate' => $preflight['checks']['frontend_uat_release_gate'] ?? null,
            ],
            'system_observability' => [
                'status' => $observability['status'] ?? 'unknown',
                'checked_at' => $observability['checked_at'] ?? null,
                'frontend_uat_release_gate' => $observability['frontend_uat_release_gate'] ?? null,
                'recommendations' => $observability['recommendations'] ?? [],
            ],
            'blocked_items' => $items,
            'go_no_go' => $this->goNoGo($status, $allowGateDisabled, $allowObservabilityCritical),
            'cutover_preconditions' => $this->cutoverPreconditions(
                $readiness,
                $preflight,
                $observability,
                $frontendGate,
                $allowGateDisabled,
                $allowObservabilityCritical,
            ),
            'remediation_plan' => $this->remediationPlan($items),
            'approval_controls' => $this->approvalControls($allowGateDisabled, $allowObservabilityCritical),
            'decision' => $this->decisionTemplate($status, $decision),
            'rollback_plan' => $this->rollbackPlan(),
            'execution_order' => $this->executionOrder(),
        ];

        return $this->persistClosure($closure);
    }

    /**
     * @param  array<string, mixed>  $readiness
     * @param  array<string, mixed>  $preflight
     * @param  array<string, mixed>  $observability
     * @return array<int, array<string, mixed>>
     */
    private function blockingItems(
        array $readiness,
        array $preflight,
        array $observability,
        bool $allowGateDisabled,
        bool $allowObservabilityCritical,
    ): array {
        $items = [];
        $frontendGate = (array) ($preflight['checks']['frontend_uat_release_gate'] ?? []);

        if (($readiness['status'] ?? 'blocked') !== 'ready_for_release') {
            $items[] = $this->item(
                'frontend_uat_release_readiness_blocked',
                'Frontend UAT release readiness is not ready_for_release.',
                'Complete signed visual evidence and rerun php artisan frontend:uat-release-readiness --json.',
                [
                    'readiness_status' => $readiness['status'] ?? 'blocked',
                    'blocked_count' => count($readiness['items'] ?? []),
                ],
            );
        }

        if (($preflight['status'] ?? 'critical') !== 'ok') {
            $items[] = $this->item(
                'system_preflight_not_ok',
                'System preflight is not ok.',
                'Run php artisan system:preflight --json --fail-on-critical and remediate all blocking items.',
                [
                    'preflight_status' => $preflight['status'] ?? 'unknown',
                    'item_count' => count($preflight['items'] ?? []),
                ],
            );
        }

        if (! $allowObservabilityCritical && ($observability['status'] ?? 'critical') === 'critical') {
            $items[] = $this->item(
                'system_observability_critical',
                'System observability report is critical.',
                'Run php artisan system:observability-report --json and remediate critical observability findings.',
                [
                    'observability_status' => $observability['status'] ?? 'unknown',
                ],
            );
        }

        if (! $allowGateDisabled && ! (bool) ($frontendGate['enabled'] ?? false)) {
            $items[] = $this->item(
                'frontend_uat_release_gate_not_enabled',
                'Frontend UAT release gate is not enabled in system preflight.',
                'Set VELMIX_FRONTEND_UAT_RELEASE_GATE_ENABLED=true before using this closure packet for cutover.',
                [
                    'enabled' => (bool) ($frontendGate['enabled'] ?? false),
                    'required' => (bool) ($frontendGate['required'] ?? false),
                    'required_environments' => $frontendGate['required_environments'] ?? [],
                ],
            );
        }

        if (($frontendGate['status'] ?? 'ok') !== 'ok') {
            $items[] = $this->item(
                'frontend_uat_release_gate_not_ok',
                'Frontend UAT release gate is not ok inside system preflight.',
                'Complete frontend release readiness or adjust the gate only with explicit release-manager approval.',
                [
                    'gate_status' => $frontendGate['status'] ?? 'unknown',
                    'gate_item_count' => count($frontendGate['items'] ?? []),
                ],
            );
        }

        return $items;
    }

    /**
     * @return array<string, mixed>
     */
    private function item(string $code, string $message, string $action, array $metricSnapshot = []): array
    {
        $item = [
            'severity' => 'critical',
            'code' => $code,
            'message' => $message,
            'action' => $action,
        ];

        if ($metricSnapshot !== []) {
            $item['metric_snapshot'] = $metricSnapshot;
        }

        return $item;
    }

    /**
     * @return array<string, mixed>
     */
    private function goNoGo(string $status, bool $allowGateDisabled, bool $allowObservabilityCritical): array
    {
        $hasOverride = $allowGateDisabled || $allowObservabilityCritical;
        $ready = $status === 'ready_for_release_closure';

        return [
            'status' => $ready && ! $hasOverride ? 'go' : 'no_go',
            'production_go_allowed' => $ready && ! $hasOverride,
            'uat_dry_run_allowed' => $ready,
            'override_present' => $hasOverride,
            'reason' => match (true) {
                ! $ready => 'Closure packet still contains blocking items.',
                $hasOverride => 'Closure is only valid for local/UAT dry-run because bypass flags are active.',
                default => 'All closure gates passed without bypass flags.',
            },
        ];
    }

    /**
     * @param  array<string, mixed>  $readiness
     * @param  array<string, mixed>  $preflight
     * @param  array<string, mixed>  $observability
     * @param  array<string, mixed>  $frontendGate
     * @return array<int, array<string, mixed>>
     */
    private function cutoverPreconditions(
        array $readiness,
        array $preflight,
        array $observability,
        array $frontendGate,
        bool $allowGateDisabled,
        bool $allowObservabilityCritical,
    ): array {
        $visualStatus = (string) ($readiness['evidence']['visual_signoff']['status'] ?? 'missing');
        $observabilityStatus = (string) ($observability['status'] ?? 'unknown');

        return [
            $this->precondition(
                'visual_signoff_signed',
                $visualStatus === 'signed',
                'Frontend visual evidence is signed by required approvers.',
                'Run php artisan frontend:uat-visual-evidence-verify --json after human evidence is completed.',
            ),
            $this->precondition(
                'release_gate_enabled',
                (bool) ($frontendGate['enabled'] ?? false) || $allowGateDisabled,
                'Frontend release gate is enabled for the target environment.',
                'Set VELMIX_FRONTEND_UAT_RELEASE_GATE_ENABLED=true before production cutover.',
                ['override_used' => $allowGateDisabled],
            ),
            $this->precondition(
                'system_preflight_ok',
                ($preflight['status'] ?? 'critical') === 'ok',
                'System preflight has no blocking items.',
                'Run php artisan system:preflight --json --fail-on-critical and remediate all blockers.',
            ),
            $this->precondition(
                'observability_not_critical',
                $observabilityStatus !== 'critical' || $allowObservabilityCritical,
                'System observability is not critical.',
                'Run php artisan system:observability-report --json and clear critical findings.',
                ['override_used' => $allowObservabilityCritical],
            ),
            $this->precondition(
                'no_production_overrides',
                ! $allowGateDisabled && ! $allowObservabilityCritical,
                'No dry-run bypass flags are present for production approval.',
                'Re-run without --allow-gate-disabled and without --allow-observability-critical for production.',
            ),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function precondition(string $code, bool $passed, string $description, string $action, array $context = []): array
    {
        $precondition = [
            'code' => $code,
            'status' => $passed ? 'passed' : 'blocked',
            'description' => $description,
            'action' => $action,
        ];

        if ($context !== []) {
            $precondition['context'] = $context;
        }

        return $precondition;
    }

    /**
     * @param  array<int, array<string, mixed>>  $items
     * @return array<int, array<string, mixed>>
     */
    private function remediationPlan(array $items): array
    {
        return array_map(function (array $item): array {
            $code = (string) ($item['code'] ?? 'unknown');
            $defaults = [
                'owner' => 'release-manager',
                'sequence' => 90,
                'command' => 'php artisan frontend:uat-release-closure-pack --json',
                'rollback_impact' => 'none; evidence-only remediation',
                'production_rule' => 'Do not approve cutover until this blocker is removed without bypass flags.',
            ];

            $overrides = match ($code) {
                'frontend_uat_release_readiness_blocked' => [
                    'owner' => 'qa-lead',
                    'sequence' => 10,
                    'command' => 'php artisan frontend:uat-visual-evidence-verify --json && php artisan frontend:uat-release-readiness --json',
                ],
                'system_preflight_not_ok' => [
                    'owner' => 'platform-engineer',
                    'sequence' => 20,
                    'command' => 'php artisan system:preflight --json --fail-on-critical',
                ],
                'system_observability_critical' => [
                    'owner' => 'sre-on-call',
                    'sequence' => 30,
                    'command' => 'php artisan system:observability-report --json',
                ],
                'frontend_uat_release_gate_not_enabled' => [
                    'owner' => 'release-manager',
                    'sequence' => 40,
                    'command' => 'Set VELMIX_FRONTEND_UAT_RELEASE_GATE_ENABLED=true and rerun php artisan system:preflight --json --fail-on-critical',
                ],
                'frontend_uat_release_gate_not_ok' => [
                    'owner' => 'qa-lead',
                    'sequence' => 50,
                    'command' => 'php artisan frontend:uat-release-readiness --json',
                ],
                default => [],
            };

            return array_merge($defaults, $overrides, [
                'blocker_code' => $code,
                'blocker_message' => $item['message'] ?? 'No detail.',
                'blocker_action' => $item['action'] ?? 'Review closure packet.',
            ]);
        }, $items);
    }

    /**
     * @return array<string, mixed>
     */
    private function approvalControls(bool $allowGateDisabled, bool $allowObservabilityCritical): array
    {
        return [
            'human_visual_signoff_required' => true,
            'release_manager_decision_required' => true,
            'production_overrides_allowed' => false,
            'dry_run_overrides' => [
                'allow_gate_disabled' => $allowGateDisabled,
                'allow_observability_critical' => $allowObservabilityCritical,
            ],
            'minimum_approvers' => [
                'qa_lead',
                'release_manager',
                'business_owner',
            ],
        ];
    }

    /**
     * @param  array{owner?: string, decided_at?: string, ticket?: string, notes?: string}  $decision
     * @return array<string, string>
     */
    private function decisionTemplate(string $status, array $decision = []): array
    {
        $owner = trim((string) ($decision['owner'] ?? ''));
        $decidedAt = trim((string) ($decision['decided_at'] ?? ''));

        return [
            'status' => $status === 'ready_for_release_closure' ? 'approved_for_cutover' : 'blocked',
            'owner' => $owner,
            'decided_at' => $owner === '' ? '' : ($decidedAt === '' ? now()->toISOString() : $decidedAt),
            'ticket' => trim((string) ($decision['ticket'] ?? '')),
            'notes' => trim((string) ($decision['notes'] ?? '')),
        ];
    }

    /**
     * @return array<int, string>
     */
    private function rollbackPlan(): array
    {
        return [
            'Do not run destructive database actions as part of frontend rollback.',
            'Disable the frontend UAT release gate by configuration only if rollback requires restoring previous preflight behavior.',
            'Revert the frontend asset deployment or switch the web server release symlink back to the previous known-good release.',
            'Preserve generated UAT JSON/Markdown artifacts for incident and release review.',
            'Rerun php artisan system:preflight --json --fail-on-critical after rollback.',
        ];
    }

    /**
     * @return array<int, string>
     */
    private function executionOrder(): array
    {
        return [
            'php artisan frontend:seed-pos-smoke --json',
            'php artisan frontend:pos-quote-first-uat-smoke --json',
            'php artisan frontend:uat-signoff-pack --json',
            'php artisan frontend:uat-visual-evidence-template --json',
            'php artisan frontend:uat-visual-evidence-verify --json',
            'php artisan frontend:uat-release-readiness --json',
            'php artisan system:preflight --json --fail-on-critical',
            'php artisan frontend:uat-release-closure-pack --json',
        ];
    }

    /**
     * @param  array<string, mixed>  $closure
     * @return array<string, mixed>
     */
    private function persistClosure(array $closure): array
    {
        $directory = FrontendUatArtifactPaths::baseDirectory().'/closure';
        File::ensureDirectoryExists($directory);

        $stamp = now()->format('Ymd-His');
        $jsonPath = $directory.'/frontend-uat-release-closure-'.$stamp.'.json';
        $markdownPath = $directory.'/frontend-uat-release-closure-'.$stamp.'.md';
        $latestJsonPath = $directory.'/frontend-uat-release-closure-latest.json';
        $latestMarkdownPath = $directory.'/frontend-uat-release-closure-latest.md';

        $closure['artifacts'] = [
            'json_path' => $jsonPath,
            'markdown_path' => $markdownPath,
            'latest_json_path' => $latestJsonPath,
            'latest_markdown_path' => $latestMarkdownPath,
        ];

        $json = json_encode($closure, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);

        if ($json === false) {
            throw new RuntimeException('Unable to encode frontend UAT release closure packet.');
        }

        $markdown = $this->renderMarkdown($closure);

        File::put($jsonPath, $json.PHP_EOL);
        File::put($latestJsonPath, $json.PHP_EOL);
        File::put($markdownPath, $markdown);
        File::put($latestMarkdownPath, $markdown);

        return $closure;
    }

    /**
     * @param  array<string, mixed>  $closure
     */
    private function renderMarkdown(array $closure): string
    {
        $lines = [
            '# Frontend UAT Release Closure Packet',
            '',
            '## Estado',
            '',
            '| Campo | Valor |',
            '|:---|:---|',
            '| Status | `'.($closure['status'] ?? 'blocked').'` |',
            '| Generado | '.($closure['generated_at'] ?? '').' |',
            '| Ambiente | '.($closure['environment'] ?? '').' |',
            '| Freshness hours | '.(string) ($closure['freshness_hours'] ?? 24).' |',
            '| Allow gate disabled | '.((bool) ($closure['allow_gate_disabled'] ?? false) ? 'yes' : 'no').' |',
            '| Allow observability critical | '.((bool) ($closure['allow_observability_critical'] ?? false) ? 'yes' : 'no').' |',
            '',
            '## Gates',
            '',
            '| Gate | Estado | Detalle |',
            '|:---|:---|:---|',
            '| Frontend UAT release readiness | `'.($closure['frontend_uat_release_readiness']['status'] ?? 'unknown').'` | items: '.(string) ($closure['frontend_uat_release_readiness']['item_count'] ?? 0).' |',
            '| System preflight | `'.($closure['system_preflight']['status'] ?? 'unknown').'` | items: '.(string) ($closure['system_preflight']['item_count'] ?? 0).' |',
            '| System observability | `'.($closure['system_observability']['status'] ?? 'unknown').'` | recommendations: '.count($closure['system_observability']['recommendations'] ?? []).' |',
            '',
            '## Go/No-Go',
            '',
            '| Campo | Valor |',
            '|:---|:---|',
            '| Status | `'.($closure['go_no_go']['status'] ?? 'no_go').'` |',
            '| Production go allowed | '.((bool) ($closure['go_no_go']['production_go_allowed'] ?? false) ? 'yes' : 'no').' |',
            '| UAT dry-run allowed | '.((bool) ($closure['go_no_go']['uat_dry_run_allowed'] ?? false) ? 'yes' : 'no').' |',
            '| Override present | '.((bool) ($closure['go_no_go']['override_present'] ?? false) ? 'yes' : 'no').' |',
            '| Reason | '.($closure['go_no_go']['reason'] ?? '').' |',
            '',
            '## Precondiciones de cutover',
            '',
            '| Codigo | Estado | Accion |',
            '|:---|:---|:---|',
        ];

        foreach (($closure['cutover_preconditions'] ?? []) as $precondition) {
            $lines[] = sprintf(
                '| `%s` | `%s` | %s |',
                $precondition['code'] ?? 'unknown',
                $precondition['status'] ?? 'blocked',
                $precondition['action'] ?? '',
            );
        }

        $lines = array_merge($lines, [
            '',
            '## Bloqueos',
            '',
        ]);

        if (($closure['blocked_items'] ?? []) === []) {
            $lines[] = '- No hay bloqueos. El cierre frontend/UAT esta listo para decision de cutover.';
        } else {
            foreach ($closure['blocked_items'] as $item) {
                $lines[] = sprintf(
                    '- [%s] %s: %s',
                    $item['severity'] ?? 'critical',
                    $item['code'] ?? 'unknown',
                    $item['message'] ?? 'Sin detalle.',
                );
            }
        }

        $lines = array_merge($lines, [
            '',
            '## Plan de remediacion',
            '',
        ]);

        if (($closure['remediation_plan'] ?? []) === []) {
            $lines[] = '- No hay remediaciones pendientes.';
        } else {
            foreach ($closure['remediation_plan'] as $step) {
                $lines[] = sprintf(
                    '- [%02d] %s -> `%s` (%s)',
                    (int) ($step['sequence'] ?? 90),
                    $step['owner'] ?? 'release-manager',
                    $step['command'] ?? 'php artisan frontend:uat-release-closure-pack --json',
                    $step['blocker_code'] ?? 'unknown',
                );
            }
        }

        $lines = array_merge($lines, [
            '',
            '## Orden de ejecucion',
            '',
        ]);

        foreach (($closure['execution_order'] ?? []) as $step) {
            $lines[] = '- `'.$step.'`';
        }

        $lines = array_merge($lines, [
            '',
            '## Rollback',
            '',
        ]);

        foreach (($closure['rollback_plan'] ?? []) as $step) {
            $lines[] = '- '.$step;
        }

        $lines = array_merge($lines, [
            '',
            '## Decision',
            '',
            '| Campo | Valor |',
            '|:---|:---|',
            '| Status | '.($closure['decision']['status'] ?? 'blocked').' |',
            '| Owner | '.($closure['decision']['owner'] ?? '').' |',
            '| Fecha | '.($closure['decision']['decided_at'] ?? '').' |',
            '| Ticket | '.($closure['decision']['ticket'] ?? '').' |',
            '| Notas | '.($closure['decision']['notes'] ?? '').' |',
            '',
        ]);

        return implode(PHP_EOL, $lines);
    }
}
