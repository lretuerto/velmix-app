<?php

namespace App\Services\Frontend;

use Illuminate\Support\Facades\File;
use RuntimeException;

class FrontendUatSignoffPacketService
{
    public function __construct(
        private readonly FrontendUatReadinessService $readinessService,
    ) {}

    /**
     * @return array<string, mixed>
     */
    public function build(
        int $tenantId,
        string $userEmail,
        string $environment,
        ?string $baseUrl = null,
    ): array {
        $readiness = $this->readinessService->summary($tenantId, $userEmail);
        $smoke = $this->latestSmokeEvidence();
        $items = $this->blockingItems($readiness, $smoke);
        $status = $items === [] ? 'ready_for_visual_signoff' : 'blocked';

        $packet = [
            'status' => $status,
            'generated_at' => now()->toISOString(),
            'environment' => trim($environment) !== '' ? trim($environment) : app()->environment(),
            'base_url' => $baseUrl !== null && trim($baseUrl) !== '' ? trim($baseUrl) : null,
            'tenant' => $readiness['tenant'] ?? null,
            'operator' => $readiness['operator'] ?? null,
            'readiness' => [
                'status' => $readiness['status'] ?? 'blocked',
                'checked_at' => $readiness['checked_at'] ?? null,
                'items' => $readiness['items'] ?? [],
            ],
            'smoke' => $smoke,
            'modules' => $this->moduleSummary($readiness['modules'] ?? []),
            'required_visual_evidence' => $this->requiredVisualEvidence(),
            'blocked_items' => $items,
            'approvals' => [
                'business_owner' => $this->approvalTemplate('Responsable negocio'),
                'operations_owner' => $this->approvalTemplate('Responsable operaciones'),
                'technical_owner' => $this->approvalTemplate('Responsable tecnico'),
            ],
            'source_documents' => [
                'runbook' => 'docs/frontend/pos-quote-first-smoke-runbook.md',
                'checklist' => 'docs/frontend/uat-signoff-checklist.md',
                'smoke_evidence' => $smoke['artifacts']['latest_evidence_path'] ?? $this->latestSmokePath(),
            ],
        ];

        return $this->persistPacket($packet);
    }

    /**
     * @return array<string, mixed>
     */
    private function latestSmokeEvidence(): array
    {
        $path = $this->latestSmokePath();

        if (! is_file($path)) {
            return [
                'status' => 'missing',
                'reason' => 'Latest POS quote-first smoke evidence is missing.',
                'artifacts' => [
                    'latest_evidence_path' => $path,
                ],
            ];
        }

        $contents = File::get($path);
        $decoded = json_decode($contents, true);

        if (! is_array($decoded)) {
            return [
                'status' => 'invalid',
                'reason' => 'Latest POS quote-first smoke evidence is not valid JSON.',
                'artifacts' => [
                    'latest_evidence_path' => $path,
                ],
            ];
        }

        return $decoded;
    }

    private function latestSmokePath(): string
    {
        return FrontendUatArtifactPaths::baseDirectory().'/pos-quote-first-smoke-latest.json';
    }

    /**
     * @param  array<string, mixed>  $readiness
     * @param  array<string, mixed>  $smoke
     * @return array<int, array<string, mixed>>
     */
    private function blockingItems(array $readiness, array $smoke): array
    {
        $items = [];

        if (($readiness['status'] ?? 'blocked') !== 'ready') {
            $items[] = [
                'module' => 'frontend',
                'code' => 'readiness.blocked',
                'message' => 'Frontend UAT readiness must be ready before creating a signoff packet.',
            ];

            foreach (($readiness['items'] ?? []) as $item) {
                $items[] = is_array($item) ? $item : [
                    'module' => 'frontend',
                    'code' => 'readiness.item',
                    'message' => (string) $item,
                ];
            }
        }

        if (($smoke['status'] ?? 'missing') !== 'passed') {
            $items[] = [
                'module' => 'pos',
                'code' => 'smoke.evidence_not_passed',
                'message' => $smoke['reason'] ?? 'POS quote-first smoke must pass before visual signoff.',
                'missing' => [
                    $smoke['artifacts']['latest_evidence_path'] ?? $this->latestSmokePath(),
                ],
            ];
        }

        return $items;
    }

    /**
     * @param  array<string, mixed>  $modules
     * @return array<string, mixed>
     */
    private function moduleSummary(array $modules): array
    {
        return collect($modules)
            ->map(fn (array $module): array => [
                'name' => $module['name'] ?? 'Modulo',
                'status' => $module['status'] ?? 'blocked',
                'frontend_path' => $module['frontend_path'] ?? null,
                'blocked_count' => (int) ($module['blocked_count'] ?? 0),
            ])
            ->all();
    }

    /**
     * @return array<string, array<int, string>>
     */
    private function requiredVisualEvidence(): array
    {
        return [
            'session' => [
                'Login web exitoso con tenant seleccionado.',
                'Captura del shell autenticado sin errores JavaScript.',
            ],
            'pos' => [
                'Busqueda por SKU y nombre para producto regular y controlado.',
                'Quote visible con subtotal, descuento, total, TTL y promocion SMOKE-PROMO10.',
                'Checkout visible sin uso de POST /pos/sales desde el navegador.',
            ],
            'cash' => [
                'Caja abierta visible con expected amount calculado desde ledger.',
                'Ledger muestra movimiento sale_cash_in de venta cash.',
            ],
            'receivables' => [
                'Venta credit visible en cartera con saldo pendiente.',
                'Statement de cliente refleja cuenta por cobrar.',
            ],
            'catalog' => [
                'Catalogo muestra productos smoke activos y controlado identificado.',
            ],
            'customers' => [
                'Cliente Smoke Farmacia UAT visible con cupo activo.',
            ],
        ];
    }

    /**
     * @return array<string, string>
     */
    private function approvalTemplate(string $role): array
    {
        return [
            'role' => $role,
            'name' => '',
            'decision' => '',
            'signed_at' => '',
            'signature' => '',
        ];
    }

    /**
     * @param  array<string, mixed>  $packet
     * @return array<string, mixed>
     */
    private function persistPacket(array $packet): array
    {
        $directory = FrontendUatArtifactPaths::baseDirectory().'/signoff';
        File::ensureDirectoryExists($directory);

        $stamp = now()->format('Ymd-His');
        $markdownPath = $directory.'/frontend-uat-signoff-'.$stamp.'.md';
        $jsonPath = $directory.'/frontend-uat-signoff-'.$stamp.'.json';
        $latestMarkdownPath = $directory.'/frontend-uat-signoff-latest.md';
        $latestJsonPath = $directory.'/frontend-uat-signoff-latest.json';

        $packet['artifacts'] = [
            'markdown_path' => $markdownPath,
            'json_path' => $jsonPath,
            'latest_markdown_path' => $latestMarkdownPath,
            'latest_json_path' => $latestJsonPath,
        ];

        $json = json_encode($packet, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);

        if ($json === false) {
            throw new RuntimeException('Unable to encode frontend UAT signoff packet.');
        }

        $markdown = $this->renderMarkdown($packet);

        File::put($markdownPath, $markdown);
        File::put($latestMarkdownPath, $markdown);
        File::put($jsonPath, $json.PHP_EOL);
        File::put($latestJsonPath, $json.PHP_EOL);

        return $packet;
    }

    /**
     * @param  array<string, mixed>  $packet
     */
    private function renderMarkdown(array $packet): string
    {
        $lines = [
            '# Frontend UAT Signoff Packet',
            '',
            '## Estado',
            '',
            '| Campo | Valor |',
            '|:---|:---|',
            '| Status | `'.($packet['status'] ?? 'blocked').'` |',
            '| Generado | '.($packet['generated_at'] ?? '').' |',
            '| Ambiente | '.($packet['environment'] ?? '').' |',
            '| Base URL | '.($packet['base_url'] ?? 'pendiente').' |',
            '| Tenant | '.($packet['tenant']['code'] ?? 'pendiente').' |',
            '| Operador | '.($packet['operator']['email'] ?? 'pendiente').' |',
            '',
            '## Evidencia automatizada',
            '',
            '| Evidencia | Estado | Referencia |',
            '|:---|:---|:---|',
            '| Readiness | `'.($packet['readiness']['status'] ?? 'blocked').'` | '.($packet['readiness']['checked_at'] ?? 'n/a').' |',
            '| POS quote-first smoke | `'.($packet['smoke']['status'] ?? 'missing').'` | '.($packet['source_documents']['smoke_evidence'] ?? 'n/a').' |',
            '',
            '## Modulos',
            '',
            '| Modulo | Estado | Ruta | Bloqueos |',
            '|:---|:---|:---|---:|',
        ];

        foreach (($packet['modules'] ?? []) as $code => $module) {
            $lines[] = sprintf(
                '| %s | `%s` | `%s` | %d |',
                $module['name'] ?? $code,
                $module['status'] ?? 'blocked',
                $module['frontend_path'] ?? 'n/a',
                (int) ($module['blocked_count'] ?? 0),
            );
        }

        $lines = array_merge($lines, [
            '',
            '## Escenarios smoke',
            '',
            '| Escenario | Estado | Venta | Total |',
            '|:---|:---|:---|---:|',
        ]);

        foreach (($packet['smoke']['scenarios'] ?? []) as $code => $scenario) {
            $lines[] = sprintf(
                '| %s | `%s` | `%s` | %.2f |',
                $code,
                $scenario['status'] ?? 'missing',
                $scenario['sale']['reference'] ?? 'n/a',
                (float) ($scenario['sale']['total_amount'] ?? 0),
            );
        }

        $lines = array_merge($lines, [
            '',
            '## Evidencia visual requerida',
            '',
        ]);

        foreach (($packet['required_visual_evidence'] ?? []) as $module => $items) {
            $lines[] = '### '.$module;
            $lines[] = '';

            foreach ($items as $item) {
                $lines[] = '- [ ] '.$item;
            }

            $lines[] = '';
        }

        $lines = array_merge($lines, [
            '## Bloqueos',
            '',
        ]);

        if (($packet['blocked_items'] ?? []) === []) {
            $lines[] = '- No hay bloqueos automatizados. Firma visual pendiente.';
        } else {
            foreach ($packet['blocked_items'] as $item) {
                $lines[] = sprintf(
                    '- [%s] %s: %s',
                    $item['module'] ?? 'frontend',
                    $item['code'] ?? 'unknown',
                    $item['message'] ?? 'Sin detalle.',
                );
            }
        }

        $lines = array_merge($lines, [
            '',
            '## Firmas',
            '',
            '| Rol | Nombre | Decision | Fecha | Firma |',
            '|:---|:---|:---|:---|:---|',
        ]);

        foreach (($packet['approvals'] ?? []) as $approval) {
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
            '## Rollback',
            '',
            '- Este paquete no muta datos de negocio.',
            '- Si el smoke transaccional falla, no firmar UAT y corregir el modulo bloqueado antes de repetir.',
            '- Si el recorrido visual falla, adjuntar captura, request id y modulo afectado.',
            '',
        ]);

        return implode(PHP_EOL, $lines);
    }
}
