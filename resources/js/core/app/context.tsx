import { useMemo, type PropsWithChildren } from 'react';
import { readAppBoot } from '@/core/app/boot';
import { AppShellContext } from '@/core/app/shell-context';

export function AppShellProvider({ children }: PropsWithChildren) {
    const boot = useMemo(() => readAppBoot(), []);

    return <AppShellContext.Provider value={boot}>{children}</AppShellContext.Provider>;
}
