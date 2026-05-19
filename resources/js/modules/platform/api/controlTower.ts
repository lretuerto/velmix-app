import { getJson } from '@/core/api/client';

export interface OperationsHealthGate {
    code: string;
    status: string;
    label: string;
    reason: string | null;
    action: string | null;
    path: string | null;
}

export interface OperationsExecutiveSummary {
    overall_status: string;
    critical_gate_count: number;
    warning_gate_count: number;
    sales_completed_total: number;
    collections_total: number;
    cash_discrepancy_total: number;
    billing_pending_backlog_count: number;
    billing_failed_backlog_count: number;
    finance_overdue_total: number;
    finance_broken_promise_count: number;
    operations_open_alert_count: number;
    operations_critical_alert_count: number;
}

export interface OperationsTimelineItem {
    date: string;
    overall_status: string;
    sales_completed_total: number;
    collections_total: number;
    cash_discrepancy_total: number;
    billing_pending_backlog_count: number;
    billing_failed_backlog_count: number;
    finance_overdue_total: number;
    operations_open_alert_count: number;
    [key: string]: unknown;
}

export interface OperationsControlTowerBriefing {
    tenant_id: number;
    date: string;
    windows: {
        history_days: number;
        billing_days: number;
        finance_days_ahead: number;
        priority_limit: number;
        failure_limit: number;
        stale_follow_up_days: number;
    };
    paths: {
        self: string;
        export_markdown: string;
        export_json: string;
        control_tower: string;
    };
    executive_summary: OperationsExecutiveSummary;
    current: {
        health_gates: Record<string, Omit<OperationsHealthGate, 'code'>>;
        action_center: {
            operations_queue: Array<Record<string, unknown>>;
            finance_priority_queue: Array<Record<string, unknown>>;
            billing_recent_failures: Array<Record<string, unknown>>;
            recommended_actions: string[];
        };
        [key: string]: unknown;
    };
    history: {
        history_window: {
            days: number;
            start_date: string;
            end_date: string;
        };
        summary: {
            status_breakdown: {
                ok_count: number;
                warning_count: number;
                critical_count: number;
            };
            worst_day: {
                date: string;
                overall_status: string;
            } | null;
            maxima: Record<string, number>;
        };
        timeline: OperationsTimelineItem[];
    };
    snapshot_context: {
        snapshot: {
            id: number;
            snapshot_date: string;
            label: string | null;
            captured_at: string | null;
            captured_by: string | null;
            detail_path: string;
            export_path: string;
            compare_path: string;
        };
        compare: {
            mode: string;
            movement: string;
            delta: {
                billing_failed_backlog_count: number;
                finance_overdue_total: number;
                operations_open_alert_count: number;
                [key: string]: number;
            };
            windows: {
                match: boolean;
            };
        };
        requested_windows_match_snapshot_windows: boolean;
    } | null;
    highlights: {
        top_health_gates: OperationsHealthGate[];
        key_actions: string[];
        trend: {
            status_breakdown: {
                ok_count: number;
                warning_count: number;
                critical_count: number;
            };
            worst_day: {
                date: string;
                overall_status: string;
            } | null;
            maxima: Record<string, number>;
        };
        snapshot_drift: {
            movement: string;
            overall_status_changed: boolean;
            billing_failed_backlog_delta: number;
            finance_overdue_total_delta: number;
            operations_open_alert_delta: number;
            windows_match: boolean;
        } | null;
        insights: string[];
    };
}

export interface ControlTowerBriefingParams {
    date?: string;
    historyDays?: number;
    billingDays?: number;
    financeDaysAhead?: number;
    priorityLimit?: number;
    failureLimit?: number;
    staleFollowUpDays?: number;
    snapshotId?: number;
}

export async function fetchOperationsControlTowerBriefing(
    params: ControlTowerBriefingParams = {},
): Promise<OperationsControlTowerBriefing> {
    const search = new URLSearchParams();

    if (params.date !== undefined) {
        search.set('date', params.date);
    }

    if (params.historyDays !== undefined) {
        search.set('history_days', String(params.historyDays));
    }

    if (params.billingDays !== undefined) {
        search.set('billing_days', String(params.billingDays));
    }

    if (params.financeDaysAhead !== undefined) {
        search.set('finance_days_ahead', String(params.financeDaysAhead));
    }

    if (params.priorityLimit !== undefined) {
        search.set('priority_limit', String(params.priorityLimit));
    }

    if (params.failureLimit !== undefined) {
        search.set('failure_limit', String(params.failureLimit));
    }

    if (params.staleFollowUpDays !== undefined) {
        search.set('stale_follow_up_days', String(params.staleFollowUpDays));
    }

    if (params.snapshotId !== undefined) {
        search.set('snapshot_id', String(params.snapshotId));
    }

    const query = search.toString();

    return getJson<OperationsControlTowerBriefing>(
        `/reports/operations-control-tower/briefing${query !== '' ? `?${query}` : ''}`,
    );
}
