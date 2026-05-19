import { useQuery } from '@tanstack/react-query';
import { useAppShell } from '@/core/app/hooks';
import { fetchPlatformObservability } from '@/modules/platform/api/observability';

export function usePlatformObservability(date?: string) {
    const boot = useAppShell();

    return useQuery({
        queryKey: ['platform-observability', boot.tenant.selected?.id ?? 0, date ?? 'live'],
        queryFn: () => fetchPlatformObservability(date),
        enabled: boot.auth.authenticated && boot.tenant.selected !== null,
    });
}
