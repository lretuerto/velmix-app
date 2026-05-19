import { useQuery } from '@tanstack/react-query';
import { useAppShell } from '@/core/app/hooks';
import {
    fetchOperationsControlTowerBriefing,
    type ControlTowerBriefingParams,
} from '@/modules/platform/api/controlTower';

export function useControlTowerBriefing(params: ControlTowerBriefingParams = {}) {
    const boot = useAppShell();
    const stableParams = {
        historyDays: params.historyDays ?? 7,
        billingDays: params.billingDays ?? 7,
        financeDaysAhead: params.financeDaysAhead ?? 7,
        priorityLimit: params.priorityLimit ?? 5,
        failureLimit: params.failureLimit ?? 5,
        staleFollowUpDays: params.staleFollowUpDays ?? 3,
        date: params.date,
        snapshotId: params.snapshotId,
    };

    return useQuery({
        queryKey: ['operations-control-tower-briefing', boot.tenant.selected?.id ?? 0, stableParams],
        queryFn: () => fetchOperationsControlTowerBriefing(stableParams),
        enabled: boot.auth.authenticated && boot.tenant.selected !== null,
    });
}
