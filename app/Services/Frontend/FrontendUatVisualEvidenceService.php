<?php

namespace App\Services\Frontend;

use Illuminate\Support\Facades\File;
use RuntimeException;

class FrontendUatVisualEvidenceService
{
    private const READY_FOR_VISUAL_SIGNOFF = 'ready_for_visual_signoff';

    private const PASSING_DECISIONS = [
        'approved',
        'approved_with_observations',
    ];

    /**
     * @return array<string, mixed>
     */
    public function createTemplate(?string $packetPath = null): array
    {
        $resolvedPacketPath = $this->resolvePacketPath($packetPath);
        $packet = $this->loadJson($resolvedPacketPath, 'frontend UAT signoff packet');

        if (($packet['status'] ?? null) !== self::READY_FOR_VISUAL_SIGNOFF) {
            throw new RuntimeException('Frontend UAT signoff packet must be ready_for_visual_signoff before generating visual evidence.');
        }

        $manifest = [
            'status' => 'draft',
            'generated_at' => now()->toISOString(),
            'packet' => $this->packetSummary($packet, $resolvedPacketPath),
            'modules' => $this->moduleTemplates($packet),
            'final_approvals' => $this->approvalTemplates($packet),
            'instructions' => [
                'Fill every module with decision, approved_by, approved_at, screenshots, network_captures and request_ids.',
                'Use approved only when the scenario is accepted. Use approved_with_observations only for non-blocking findings with tickets attached.',
                'Do not mark signed from automation without human visual evidence.',
            ],
        ];

        return $this->persistTemplate($manifest);
    }

    /**
     * @return array<string, mixed>
     */
    public function verify(?string $manifestPath = null, ?string $packetPath = null): array
    {
        $resolvedManifestPath = $this->resolveManifestPath($manifestPath);
        $resolvedPacketPath = $this->resolvePacketPath($packetPath);
        $manifest = $this->loadJson($resolvedManifestPath, 'frontend UAT visual evidence manifest');
        $packet = $this->loadJson($resolvedPacketPath, 'frontend UAT signoff packet');

        $blockedItems = $this->blockedItems($manifest, $packet, $resolvedManifestPath, $resolvedPacketPath);
        $status = $blockedItems === [] ? 'signed' : 'blocked';

        $report = [
            'status' => $status,
            'verified_at' => now()->toISOString(),
            'manifest_path' => $resolvedManifestPath,
            'packet_path' => $resolvedPacketPath,
            'packet' => $this->packetSummary($packet, $resolvedPacketPath),
            'module_count' => count($manifest['modules'] ?? []),
            'approval_count' => count($manifest['final_approvals'] ?? []),
            'blocked_items' => $blockedItems,
            'visual_evidence' => [
                'modules' => $manifest['modules'] ?? [],
                'final_approvals' => $manifest['final_approvals'] ?? [],
            ],
        ];

        return $this->persistVerification($report);
    }

    private function latestPacketPath(): string
    {
        return FrontendUatArtifactPaths::baseDirectory().'/signoff/frontend-uat-signoff-latest.json';
    }

    private function latestManifestPath(): string
    {
        return FrontendUatArtifactPaths::baseDirectory().'/signoff/frontend-uat-visual-evidence-latest.json';
    }

    private function resolvePacketPath(?string $packetPath): string
    {
        return $packetPath !== null && trim($packetPath) !== ''
            ? trim($packetPath)
            : $this->latestPacketPath();
    }

    private function resolveManifestPath(?string $manifestPath): string
    {
        return $manifestPath !== null && trim($manifestPath) !== ''
            ? trim($manifestPath)
            : $this->latestManifestPath();
    }

    /**
     * @return array<string, mixed>
     */
    private function loadJson(string $path, string $label): array
    {
        if (! is_file($path)) {
            throw new RuntimeException(sprintf('Missing %s at %s.', $label, $path));
        }

        $decoded = json_decode(File::get($path), true);

        if (! is_array($decoded)) {
            throw new RuntimeException(sprintf('Invalid JSON for %s at %s.', $label, $path));
        }

        return $decoded;
    }

    /**
     * @param  array<string, mixed>  $packet
     * @return array<string, mixed>
     */
    private function packetSummary(array $packet, string $path): array
    {
        return [
            'path' => $path,
            'status' => $packet['status'] ?? 'unknown',
            'generated_at' => $packet['generated_at'] ?? null,
            'environment' => $packet['environment'] ?? null,
            'base_url' => $packet['base_url'] ?? null,
            'tenant_code' => $packet['tenant']['code'] ?? null,
            'operator_email' => $packet['operator']['email'] ?? null,
        ];
    }

    /**
     * @param  array<string, mixed>  $packet
     * @return array<string, mixed>
     */
    private function moduleTemplates(array $packet): array
    {
        $modules = [];
        $packetModules = is_array($packet['modules'] ?? null) ? $packet['modules'] : [];
        $requiredEvidence = is_array($packet['required_visual_evidence'] ?? null) ? $packet['required_visual_evidence'] : [];

        foreach (array_unique(array_merge(array_keys($packetModules), array_keys($requiredEvidence))) as $moduleCode) {
            $packetModule = is_array($packetModules[$moduleCode] ?? null) ? $packetModules[$moduleCode] : [];

            $modules[$moduleCode] = [
                'name' => $packetModule['name'] ?? $moduleCode,
                'frontend_path' => $packetModule['frontend_path'] ?? null,
                'required_evidence' => array_values(is_array($requiredEvidence[$moduleCode] ?? null) ? $requiredEvidence[$moduleCode] : []),
                'decision' => '',
                'approved_by' => '',
                'approved_at' => '',
                'screenshots' => [],
                'network_captures' => [],
                'request_ids' => [],
                'tickets' => [],
                'notes' => '',
            ];
        }

        return $modules;
    }

    /**
     * @param  array<string, mixed>  $packet
     * @return array<string, mixed>
     */
    private function approvalTemplates(array $packet): array
    {
        $approvals = [];
        $packetApprovals = is_array($packet['approvals'] ?? null) ? $packet['approvals'] : [];

        foreach (['business_owner', 'operations_owner', 'technical_owner'] as $approvalCode) {
            $approval = is_array($packetApprovals[$approvalCode] ?? null) ? $packetApprovals[$approvalCode] : [];

            $approvals[$approvalCode] = [
                'role' => $approval['role'] ?? $approvalCode,
                'name' => '',
                'decision' => '',
                'signed_at' => '',
                'signature' => '',
                'notes' => '',
            ];
        }

        return $approvals;
    }

    /**
     * @param  array<string, mixed>  $manifest
     * @return array<string, mixed>
     */
    private function persistTemplate(array $manifest): array
    {
        $directory = FrontendUatArtifactPaths::baseDirectory().'/signoff';
        File::ensureDirectoryExists($directory);

        $stamp = now()->format('Ymd-His');
        $jsonPath = $directory.'/frontend-uat-visual-evidence-'.$stamp.'.json';
        $markdownPath = $directory.'/frontend-uat-visual-evidence-'.$stamp.'.md';
        $latestJsonPath = $this->latestManifestPath();
        $latestMarkdownPath = $directory.'/frontend-uat-visual-evidence-latest.md';

        $manifest['artifacts'] = [
            'json_path' => $jsonPath,
            'markdown_path' => $markdownPath,
            'latest_json_path' => $latestJsonPath,
            'latest_markdown_path' => $latestMarkdownPath,
        ];

        $json = json_encode($manifest, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);

        if ($json === false) {
            throw new RuntimeException('Unable to encode frontend UAT visual evidence manifest.');
        }

        $markdown = $this->renderTemplateMarkdown($manifest);

        File::put($jsonPath, $json.PHP_EOL);
        File::put($latestJsonPath, $json.PHP_EOL);
        File::put($markdownPath, $markdown);
        File::put($latestMarkdownPath, $markdown);

        return $manifest;
    }

    /**
     * @param  array<string, mixed>  $report
     * @return array<string, mixed>
     */
    private function persistVerification(array $report): array
    {
        $directory = FrontendUatArtifactPaths::baseDirectory().'/signoff';
        File::ensureDirectoryExists($directory);

        $stamp = now()->format('Ymd-His');
        $jsonPath = $directory.'/frontend-uat-visual-signoff-'.$stamp.'.json';
        $markdownPath = $directory.'/frontend-uat-visual-signoff-'.$stamp.'.md';
        $latestJsonPath = $directory.'/frontend-uat-visual-signoff-latest.json';
        $latestMarkdownPath = $directory.'/frontend-uat-visual-signoff-latest.md';

        $report['artifacts'] = [
            'json_path' => $jsonPath,
            'markdown_path' => $markdownPath,
            'latest_json_path' => $latestJsonPath,
            'latest_markdown_path' => $latestMarkdownPath,
        ];

        $json = json_encode($report, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);

        if ($json === false) {
            throw new RuntimeException('Unable to encode frontend UAT visual signoff report.');
        }

        $markdown = $this->renderVerificationMarkdown($report);

        File::put($jsonPath, $json.PHP_EOL);
        File::put($latestJsonPath, $json.PHP_EOL);
        File::put($markdownPath, $markdown);
        File::put($latestMarkdownPath, $markdown);

        return $report;
    }

    /**
     * @param  array<string, mixed>  $manifest
     * @param  array<string, mixed>  $packet
     * @return array<int, array<string, mixed>>
     */
    private function blockedItems(array $manifest, array $packet, string $manifestPath, string $packetPath): array
    {
        $blockedItems = [];

        if (($packet['status'] ?? null) !== self::READY_FOR_VISUAL_SIGNOFF) {
            $blockedItems[] = [
                'module' => 'frontend',
                'code' => 'packet.not_ready',
                'message' => 'Frontend UAT signoff packet is not ready_for_visual_signoff.',
                'path' => $packetPath,
            ];
        }

        if (! is_array($manifest['modules'] ?? null)) {
            $blockedItems[] = [
                'module' => 'frontend',
                'code' => 'manifest.modules_missing',
                'message' => 'Visual evidence manifest must contain modules.',
                'path' => $manifestPath,
            ];

            return $blockedItems;
        }

        foreach ($this->expectedModuleCodes($packet) as $moduleCode) {
            $module = $manifest['modules'][$moduleCode] ?? null;

            if (! is_array($module)) {
                $blockedItems[] = $this->blockedItem($moduleCode, 'module.missing', 'Module visual evidence is missing.');

                continue;
            }

            $blockedItems = array_merge($blockedItems, $this->moduleBlockedItems($moduleCode, $module));
        }

        if (! is_array($manifest['final_approvals'] ?? null)) {
            $blockedItems[] = [
                'module' => 'frontend',
                'code' => 'approvals.missing',
                'message' => 'Final approvals are missing.',
            ];

            return $blockedItems;
        }

        foreach (['business_owner', 'operations_owner', 'technical_owner'] as $approvalCode) {
            $approval = $manifest['final_approvals'][$approvalCode] ?? null;

            if (! is_array($approval)) {
                $blockedItems[] = $this->blockedItem($approvalCode, 'approval.missing', 'Final approval is missing.');

                continue;
            }

            $blockedItems = array_merge($blockedItems, $this->approvalBlockedItems($approvalCode, $approval));
        }

        return $blockedItems;
    }

    /**
     * @param  array<string, mixed>  $packet
     * @return array<int, string>
     */
    private function expectedModuleCodes(array $packet): array
    {
        $modules = is_array($packet['modules'] ?? null) ? array_keys($packet['modules']) : [];
        $requiredEvidence = is_array($packet['required_visual_evidence'] ?? null) ? array_keys($packet['required_visual_evidence']) : [];

        return array_values(array_unique(array_merge($modules, $requiredEvidence)));
    }

    /**
     * @param  array<string, mixed>  $module
     * @return array<int, array<string, mixed>>
     */
    private function moduleBlockedItems(string $moduleCode, array $module): array
    {
        $blockedItems = [];

        if (! $this->isPassingDecision($module['decision'] ?? null)) {
            $blockedItems[] = $this->blockedItem($moduleCode, 'module.decision_missing', 'Module decision must be approved or approved_with_observations.');
        }

        foreach (['approved_by', 'approved_at'] as $field) {
            if (! $this->filledString($module[$field] ?? null)) {
                $blockedItems[] = $this->blockedItem($moduleCode, 'module.'.$field.'_missing', 'Module '.$field.' is required.');
            }
        }

        foreach (['screenshots', 'network_captures', 'request_ids'] as $field) {
            if (! $this->nonEmptyStringList($module[$field] ?? null)) {
                $blockedItems[] = $this->blockedItem($moduleCode, 'module.'.$field.'_missing', 'Module '.$field.' must include at least one non-empty reference.');
            }
        }

        return $blockedItems;
    }

    /**
     * @param  array<string, mixed>  $approval
     * @return array<int, array<string, mixed>>
     */
    private function approvalBlockedItems(string $approvalCode, array $approval): array
    {
        $blockedItems = [];

        if (! $this->isPassingDecision($approval['decision'] ?? null)) {
            $blockedItems[] = $this->blockedItem($approvalCode, 'approval.decision_missing', 'Final approval decision must be approved or approved_with_observations.');
        }

        foreach (['name', 'signed_at', 'signature'] as $field) {
            if (! $this->filledString($approval[$field] ?? null)) {
                $blockedItems[] = $this->blockedItem($approvalCode, 'approval.'.$field.'_missing', 'Final approval '.$field.' is required.');
            }
        }

        return $blockedItems;
    }

    /**
     * @return array<string, string>
     */
    private function blockedItem(string $module, string $code, string $message): array
    {
        return [
            'module' => $module,
            'code' => $code,
            'message' => $message,
        ];
    }

    private function isPassingDecision(mixed $decision): bool
    {
        return is_string($decision) && in_array(trim($decision), self::PASSING_DECISIONS, true);
    }

    private function filledString(mixed $value): bool
    {
        return is_string($value) && trim($value) !== '';
    }

    private function nonEmptyStringList(mixed $value): bool
    {
        if (! is_array($value) || $value === []) {
            return false;
        }

        foreach ($value as $item) {
            if ($this->filledString($item)) {
                return true;
            }
        }

        return false;
    }

    /**
     * @param  array<string, mixed>  $manifest
     */
    private function renderTemplateMarkdown(array $manifest): string
    {
        $lines = [
            '# Frontend UAT Visual Evidence Manifest',
            '',
            '## Estado',
            '',
            '| Campo | Valor |',
            '|:---|:---|',
            '| Status | `'.($manifest['status'] ?? 'draft').'` |',
            '| Generado | '.($manifest['generated_at'] ?? '').' |',
            '| Packet | '.($manifest['packet']['path'] ?? '').' |',
            '| Tenant | '.($manifest['packet']['tenant_code'] ?? 'pendiente').' |',
            '| Base URL | '.($manifest['packet']['base_url'] ?? 'pendiente').' |',
            '',
            '## Modulos',
            '',
            '| Modulo | Decision | Evidencia minima |',
            '|:---|:---|:---|',
        ];

        foreach (($manifest['modules'] ?? []) as $code => $module) {
            $lines[] = sprintf(
                '| %s | `%s` | screenshots, network_captures, request_ids |',
                $module['name'] ?? $code,
                $module['decision'] ?? 'pendiente',
            );
        }

        $lines = array_merge($lines, [
            '',
            '## Firmas finales',
            '',
            '| Rol | Nombre | Decision | Fecha | Firma |',
            '|:---|:---|:---|:---|:---|',
        ]);

        foreach (($manifest['final_approvals'] ?? []) as $approval) {
            $lines[] = sprintf(
                '| %s | %s | %s | %s | %s |',
                $approval['role'] ?? '',
                $approval['name'] ?? '',
                $approval['decision'] ?? '',
                $approval['signed_at'] ?? '',
                $approval['signature'] ?? '',
            );
        }

        $lines = array_merge($lines, [
            '',
            '## Como completar',
            '',
            '- Completar el JSON latest con evidencia real, no generada automaticamente.',
            '- Ejecutar `php artisan frontend:uat-visual-evidence-verify --json`.',
            '- Solo el estado `signed` cierra la firma visual.',
            '',
        ]);

        return implode(PHP_EOL, $lines);
    }

    /**
     * @param  array<string, mixed>  $report
     */
    private function renderVerificationMarkdown(array $report): string
    {
        $lines = [
            '# Frontend UAT Visual Signoff Verification',
            '',
            '## Estado',
            '',
            '| Campo | Valor |',
            '|:---|:---|',
            '| Status | `'.($report['status'] ?? 'blocked').'` |',
            '| Verificado | '.($report['verified_at'] ?? '').' |',
            '| Manifest | '.($report['manifest_path'] ?? '').' |',
            '| Packet | '.($report['packet_path'] ?? '').' |',
            '',
            '## Bloqueos',
            '',
        ];

        if (($report['blocked_items'] ?? []) === []) {
            $lines[] = '- No hay bloqueos. Firma visual completa.';
        } else {
            foreach ($report['blocked_items'] as $item) {
                $lines[] = sprintf(
                    '- [%s] %s: %s',
                    $item['module'] ?? 'frontend',
                    $item['code'] ?? 'unknown',
                    $item['message'] ?? 'Sin detalle.',
                );
            }
        }

        $lines[] = '';

        return implode(PHP_EOL, $lines);
    }
}
