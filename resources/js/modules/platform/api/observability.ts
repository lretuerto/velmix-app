import { getJson } from '@/core/api/client';

export interface ObservabilityDeliveryChannel {
    channel: string;
    status: string;
    [key: string]: unknown;
}

export interface ObservabilityBackupManifest {
    artifact?: string;
    recorded_at?: string;
    generated_at?: string;
    checksum?: string;
    size_bytes?: number;
    driver?: string;
    manifest_path?: string;
    report_path?: string;
    release?: string;
    [key: string]: unknown;
}

export interface ObservabilityStatusBlock {
    status: string;
    latest_backup?: ObservabilityBackupManifest | null;
    latest_drill?: ObservabilityBackupManifest | null;
    latest_certification?: ObservabilityBackupManifest | null;
    latest_approval?: ObservabilityBackupManifest | null;
    latest_decision?: ObservabilityBackupManifest | null;
    latest_certificate?: ObservabilityBackupManifest | null;
    certificate_recorded?: boolean;
    approval_recorded?: boolean;
    decision_recorded?: boolean;
    operationally_certified?: boolean;
    ready_for_cutover?: boolean;
    [key: string]: unknown;
}

export interface PlatformObservabilityReport {
    tenant_id: number;
    status: string;
    checked_at: string;
    request_correlation: {
        request_id_header: string;
        response_header: string;
    };
    alerts: {
        status: string;
        summary: Record<string, unknown>;
    };
    logging: {
        default_channel?: string | null;
        effective_channels?: string[];
        structured_logging_enabled?: boolean;
    };
    notifications: {
        channels: string[];
        minimum_severity: string;
        cooldown_minutes: number;
        webhook_enabled: boolean;
        slack_enabled: boolean;
        log_channel: string;
    };
    delivery: {
        candidate_alert_count?: number;
        channels?: ObservabilityDeliveryChannel[];
        [key: string]: unknown;
    };
    recovery: {
        backup: ObservabilityStatusBlock;
        restore_drill: ObservabilityStatusBlock;
    };
    certification: {
        staging: ObservabilityStatusBlock;
    };
    promotion: ObservabilityStatusBlock;
    cutover: ObservabilityStatusBlock;
    operational_certification: ObservabilityStatusBlock;
    recommendations: string[];
    [key: string]: unknown;
}

export async function fetchPlatformObservability(date?: string): Promise<PlatformObservabilityReport> {
    const search = new URLSearchParams();

    if (date !== undefined && date !== '') {
        search.set('date', date);
    }

    const query = search.toString();

    return getJson<PlatformObservabilityReport>(
        `/reports/platform-observability${query !== '' ? `?${query}` : ''}`,
    );
}
